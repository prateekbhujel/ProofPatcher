import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);

function load(path, imports = {}) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(
    mod.exports, (id) => imports[id] ?? require(id), mod,
  );
  return mod.exports;
}

const { IssueHeaderCard } = load('../../resources/js/Components/workbench/IssueHeaderCard.tsx');
const { GuidedStepBar } = load('../../resources/js/Components/workbench/GuidedStepBar.tsx');

// Keep the page's state across server renders so the failed response is tested
// after an issue load has requested the workbench, not only on a fresh mount.
function pageHarness() {
  const states = [];
  let cursor = 0;
  let sidebar;
  let pendingLoad;
  const hooks = {
    ...React,
    useState(initial) {
      const index = cursor++;
      if (!(index in states)) states[index] = typeof initial === 'function' ? initial() : initial;
      return [states[index], (update) => {
        states[index] = typeof update === 'function' ? update(states[index]) : update;
      }];
    },
    useEffect() {},
    useMemo: (calculate) => calculate(),
    useRef: (current) => ({ current }),
  };
  const blank = () => null;
  const Issues = load('../../resources/js/Pages/Issues.tsx', {
    react: hooks,
    '@inertiajs/react': {
      Head: blank,
      router: { post(url, data, callbacks) { pendingLoad = { url, data, callbacks }; } },
      useForm: (data) => ({ data, setData() {}, post() {}, processing: false }),
    },
    '../Components/AppLayout': { AppLayout: ({ children }) => React.createElement('main', null, children) },
    '../Components/ui/copyText': {},
    '../Components/TaskCardSection': {
      NewTaskComposer: () => React.createElement('form', { 'aria-label': 'Task details' }),
      FocusedLocalTaskView: blank,
    },
    '../Components/workbench/DisconnectedOnboardingView': { DisconnectedOnboardingView: () => React.createElement('p', null, 'Connect GitHub') },
    '../Components/workbench/WorkbenchSidebar': { WorkbenchSidebar: (props) => { sidebar = props; return null; } },
    '../Components/workbench/GuidedStepBar': { GuidedStepBar },
    '../Components/workbench/IssueHeaderCard': { IssueHeaderCard },
    '../Components/workbench/WorkbenchScreenshot': { WorkbenchScreenshot: blank },
    '../Components/workbench/ProposalDiffCard': { ProposalDiffCard: blank },
    '../Components/workbench/VerificationPipelineCard': { VerificationPipelineCard: blank },
    '../Components/workbench/TaskComposerFooter': { TaskComposerFooter: blank },
    '../Components/console/shared': { COMPOSER_DRAFT_KEY: 'test-composer-draft' },
    '../hooks/useKernelPrompt': { useKernelPrompt: () => ({ commandFeedback: null, activeOpenPull: null, executePrompt() {} }) },
  }).default;

  return {
    render(props) {
      cursor = 0;
      return renderToStaticMarkup(React.createElement(Issues, props));
    },
    loadIssue() {
      sidebar.handleLoadIssue({ number: 7 });
      assert.equal(pendingLoad.url, '/issues/load-issue');
    },
    finishResponse() {
      pendingLoad.callbacks.onSuccess();
      pendingLoad.callbacks.onFinish();
    },
  };
}

const props = {
  context: null, preview: null, suggestion: null, draft: null, verificationReport: null,
  isOwnedFixture: false, isDedicatedTestRepo: true, publishedPr: null, budget: null, error: null,
  githubConnection: {
    isConnected: true, user: { login: 'owner' }, selectedRepo: 'owner/workflow-test',
    dedicatedIssueNumber: 7, repoIssues: [], repoPulls: [], repositoryCount: 1,
  },
};

const context = {
  repository: 'owner/workflow-test', issueNumber: 7,
  issueUrl: 'https://github.com/owner/workflow-test/issues/7', title: 'Fix the order total',
  body: 'Zero-quantity items must not increase the total.', path: 'app/OrderTotal.php',
  source: '<?php', sourceSha256: 'a'.repeat(64), commit: 'b'.repeat(40),
};

test('a failed connected issue load shows its error and restores a usable task pane', () => {
  const page = pageHarness();
  page.render(props);
  page.loadIssue();
  assert.match(page.render(props), /role="status"[^>]*>Loading issue #7…/);
  // Inertia can return a successful page response carrying a domain error and
  // no issue context. Its success callback must not leave an empty workbench.
  page.finishResponse();
  const html = page.render({ ...props, error: 'The selected issue could not be loaded.' });
  assert.match(html, /role="alert"[^>]*>The selected issue could not be loaded\./);
  assert.match(html, /<h1[^>]*>New task<\/h1>/);
  assert.match(html, /Create a task or open an issue from owner\/workflow-test/);
  assert.match(html, /<form aria-label="Task details"/);
  assert.doesNotMatch(html, /aria-label="Issue progress"|aria-label="Issue details"|Loading issue #7/);
});

test('a successful issue load displays the issue workbench instead of the task fallback', () => {
  const page = pageHarness();
  page.render(props);
  page.loadIssue();
  page.finishResponse();
  const html = page.render({ ...props, context });
  assert.match(html, /aria-label="Issue progress"/);
  assert.match(html, /aria-label="Issue details"/);
  assert.match(html, /<h1[^>]*>Fix the order total<\/h1>/);
  assert.match(html, /Zero-quantity items must not increase the total\./);
  assert.doesNotMatch(html, /<h1[^>]*>New task<\/h1>|aria-label="Task details"|role="alert"/);
});
