import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
function loadTsx(relativePath, resolve = require) {
  const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(mod.exports, resolve, mod);
  return mod.exports;
}
/** Recursive TSX loader: resolves relative imports against the importing file. */
function createLoader() {
  const cache = new Map();
  function resolveFile(base, spec) {
    const url = new URL(spec, pathToFileURL(base));
    for (const candidate of [url.pathname, `${url.pathname}.tsx`, `${url.pathname}.ts`]) {
      try {
        readFileSync(candidate, 'utf8');
        return candidate;
      } catch {
        /* try next */
      }
    }
    throw new Error(`Cannot resolve ${spec} from ${base}`);
  }
  function load(absPath) {
    if (cache.has(absPath)) return cache.get(absPath).exports;
    const source = readFileSync(absPath, 'utf8');
    const loader = absPath.endsWith('.ts') ? 'ts' : 'tsx';
    const { code } = transformSync(source, { loader, format: 'cjs' });
    const mod = { exports: {} };
    cache.set(absPath, mod);
    const localRequire = (id) => {
      if (id === '@inertiajs/react') return inertia;
      if (id.startsWith('.')) return load(resolveFile(absPath, id));
      return require(id);
    };
    new Function('exports', 'require', 'module', code)(mod.exports, localRequire, mod);
    return mod.exports;
  }
  return { load, resolveFile };
}
const inertia = {
  Link: ({ children, href, ...props }) => React.createElement('a', { href, ...props }, children),
  Head: ({ title }) => React.createElement('title', {}, title),
  router: { post() {} },
  usePage: () => ({ props: {} }),
};
const logo = loadTsx('../../resources/js/Components/ui/BrandMark.tsx');
const signInButtonModule = loadTsx('../../resources/js/Components/ui/SignInWithGitHubButton.tsx');
const repoPicker = loadTsx('../../resources/js/Components/workbench/RepoSearchPicker.tsx');
const sidebarRequire = (id) => {
  if (id === '@inertiajs/react') return inertia;
  if (id === './RepoSearchPicker') return repoPicker;
  return require(id);
};
const shellLoader = createLoader();
const layout = shellLoader.load(shellLoader.resolveFile(fileURLToPath(import.meta.url), '../../resources/js/Components/AppLayout.tsx'));
const onboarding = loadTsx('../../resources/js/Components/workbench/DisconnectedOnboardingView.tsx', (id) => {
  if (id === '@inertiajs/react') return inertia;
  if (id === '../AppLayout') return layout;
  if (id === '../ui/BrandMark') return logo;
  if (id === '../ui/SignInWithGitHubButton') return signInButtonModule;
  return require(id);
});

const baseProps = {};

test('an unconfigured installation explains the missing GitHub App ID', () => {
  const html = renderToStaticMarkup(React.createElement(onboarding.DisconnectedOnboardingView, baseProps));
  assert.match(html, /Sign in with GitHub/);
  assert.match(html, /GitHub App client ID/);
  assert.match(html, /href="\/github\/authorize\?redirect_to=%2Fissues"/);
  assert.match(html, /href="\/settings"/);
  assert.doesNotMatch(html, /\$15\.00|\$100\.00|Open OrderTotal|Launch Interactive/);
});

test('a configured installation offers sign-in without asking the user for an App ID', () => {
  const html = renderToStaticMarkup(React.createElement(onboarding.DisconnectedOnboardingView, {
    ...baseProps, githubConnection: { isConfigured: true },
  }));
  assert.match(html, /Sign in with GitHub/);
  assert.match(html, /bring you back here/);
  assert.doesNotMatch(html, /GitHub App client ID|short code/);
});

test('the repository sidebar shows tasks only for the selected repository', () => {
  const sidebar = loadTsx('../../resources/js/Components/workbench/WorkbenchSidebar.tsx', sidebarRequire);
  const connection = { selectedRepo: 'owner/one', repositories: [{ fullName: 'owner/one' }], repoIssues: [], repoPulls: [], user: { login: 'owner' } };
  const html = renderToStaticMarkup(React.createElement(sidebar.WorkbenchSidebar, {
    githubConnection: connection, sidebarTab: 'issues', sidebarQuery: '', filteredIssues: [], filteredPulls: [], issuePathMap: {},
    localTasks: [{ id: 1, title: 'Selected task', repository: 'owner/one' }, { id: 2, title: 'Other task', repository: 'owner/two' }],
    handleSelectRepo() {}, setSidebarTab() {}, setSidebarQuery() {}, setIssuePathMap() {}, handleLoadIssue() {}, setViewMode() {}, onSelectTask() {},
  }));
  assert.match(html, /Selected task/);
  assert.doesNotMatch(html, /Other task/);
  assert.match(html, /Issues \(0\)/);
  assert.match(html, /Pull Requests \(0\)/);
  // Account controls moved to the start menu; the sidebar stays task-focused.
  assert.doesNotMatch(html, /href="\/settings"/);
  assert.doesNotMatch(html, /Log out/);
  // The repo switcher is the async searchable picker, not a native select.
  assert.match(html, /role="combobox"/);
  assert.match(html, /aria-label="Select connected repository"/);
  assert.doesNotMatch(html, /<select/);
});

test('the dedicated test issue opens without a file path while other issues still need one', () => {
  const sidebar = loadTsx('../../resources/js/Components/workbench/WorkbenchSidebar.tsx', sidebarRequire);
  const connection = { selectedRepo: 'owner/test', dedicatedIssueNumber: 1, repositories: [{ fullName: 'owner/test' }], repoIssues: [], repoPulls: [], user: { login: 'owner' } };
  const issues = [
    { number: 1, title: 'Known test issue', user: 'owner', createdAt: '2026-10-30T00:00:00Z' },
    { number: 2, title: 'Another issue', user: 'owner', createdAt: '2026-10-30T00:00:00Z' },
  ];
  const html = renderToStaticMarkup(React.createElement(sidebar.WorkbenchSidebar, {
    githubConnection: connection, sidebarTab: 'issues', sidebarQuery: '', filteredIssues: issues, filteredPulls: [], issuePathMap: {}, localTasks: [],
    handleSelectRepo() {}, setSidebarTab() {}, setSidebarQuery() {}, setIssuePathMap() {}, handleLoadIssue() {}, setViewMode() {}, onSelectTask() {},
  }));
  assert.equal((html.match(/placeholder="Path to file"/g) || []).length, 1);
  assert.equal((html.match(/<button[^>]*disabled=""[^>]*>Open<\/button>/g) || []).length, 1);
});

test('the web onboarding has no device-code fallback', () => {
  const html = renderToStaticMarkup(React.createElement(onboarding.DisconnectedOnboardingView, {
    ...baseProps, githubConnection: { isConfigured: true },
  }));
  assert.match(html, /Sign in with GitHub/);
  assert.doesNotMatch(html, /device|user code|verification code/i);
});

test('provider balances and inactive automation preferences are not presented as live services', () => {
  const source = readFileSync(new URL('../../resources/js/Pages/Settings.tsx', import.meta.url), 'utf8');
  const autoReview = readFileSync(new URL('../../resources/js/Components/settings/AutoReviewPanel.tsx', import.meta.url), 'utf8');
  const sidebar = readFileSync(new URL('../../resources/js/Components/workbench/WorkbenchSidebar.tsx', import.meta.url), 'utf8');
  assert.match(autoReview, /Automatic actions are not active yet/);
  assert.match(source, /Local estimates are not provider balances or billing limits/);
  assert.doesNotMatch(source + sidebar, /\$15\.00|\$100\.00|Meta \$15/);
  assert.doesNotMatch(sidebar, /Open OrderTotal Case|5-Gate Case/);
});

test('the app uses its shared PP icon', () => {
  const html = renderToStaticMarkup(React.createElement(logo.BrandMark, { size: 32 }));
  assert.match(html, /<img[^>]*src="\/icon\.svg"[^>]*width="32"[^>]*height="32"/);
});
