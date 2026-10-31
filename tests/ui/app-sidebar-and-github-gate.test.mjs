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
const shellLoader = createLoader();
const layout = shellLoader.load(shellLoader.resolveFile(fileURLToPath(import.meta.url), '../../resources/js/Components/AppLayout.tsx'));
const onboarding = loadTsx('../../resources/js/Components/workbench/DisconnectedOnboardingView.tsx', (id) => {
  if (id === '@inertiajs/react') return inertia;
  if (id === '../AppLayout') return layout;
  if (id === '../ui/BrandMark') return logo;
  return require(id);
});

const baseProps = {
  deviceClientIdInput: '', setDeviceClientIdInput() {}, isSubmittingDevice: false,
  handleInitiateDeviceFlow() {}, handlePollDeviceFlow() {}, copyDeviceCode() {}, copiedDeviceCode: false,
};

test('the workspace runs fullscreen in the shell; app pages get the window chrome', () => {
  const body = React.createElement('p', {}, 'Page content');
  const workspace = renderToStaticMarkup(React.createElement(layout.AppLayout, { activeNav: 'issues' }, body));
  assert.match(workspace, /Page content/);
  assert.doesNotMatch(workspace, /<aside/);
  assert.doesNotMatch(workspace, /app window/);
  assert.match(workspace, /aria-label="ProofPatcher Kernel home"/);
  assert.match(workspace, /Open start menu/);
  const settings = renderToStaticMarkup(React.createElement(layout.AppLayout, { activeNav: 'settings' }, body));
  assert.match(settings, /settings app window/);
  assert.match(settings, /Close settings and return to desktop/);
  assert.match(settings, /href="\/"[^>]*aria-label="ProofPatcher Kernel home"/);
  assert.match(settings, /Open start menu/);
});

test('the shell is honest while status loads: placeholders, never offline claims', () => {
  const body = React.createElement('p', {}, 'Page content');
  const html = renderToStaticMarkup(React.createElement(layout.AppLayout, { activeNav: 'settings' }, body));
  assert.match(html, /status loading/);
  assert.match(html, /···/);
  assert.doesNotMatch(html, /not connected/);
  assert.doesNotMatch(html, /autopilot offline/);
});

test('embedded pages render bare content with no nested chrome', () => {
  const shell = shellLoader.load(shellLoader.resolveFile(fileURLToPath(import.meta.url), '../../resources/js/Components/os/OsShell.tsx'));
  const body = React.createElement('p', {}, 'Page content');
  const html = renderToStaticMarkup(React.createElement(shell.OsShell, { appId: 'docs', embed: true }, body));
  assert.match(html, /Page content/);
  assert.doesNotMatch(html, /ProofPatcher Kernel home/);
  assert.doesNotMatch(html, /Open start menu/);
  assert.doesNotMatch(html, /app window/);
});

test('an unconfigured installation explains the missing GitHub App ID', () => {
  const html = renderToStaticMarkup(React.createElement(onboarding.DisconnectedOnboardingView, baseProps));
  assert.match(html, /Continue with GitHub/);
  assert.match(html, /GitHub App client ID/);
  assert.match(html, /<button[^>]*\sdisabled=""/);
  assert.match(html, /href="\/settings"/);
  assert.doesNotMatch(html, /\$15\.00|\$100\.00|Open OrderTotal|Launch Interactive/);
});

test('a configured installation offers sign-in without asking the user for an App ID', () => {
  const html = renderToStaticMarkup(React.createElement(onboarding.DisconnectedOnboardingView, {
    ...baseProps, githubConnection: { isConfigured: true },
  }));
  assert.match(html, /Continue with GitHub/);
  assert.match(html, /short code on GitHub/);
  assert.doesNotMatch(html, /GitHub App client ID|<button[^>]*\sdisabled=""/);
});

test('the repository sidebar shows tasks only for the selected repository', () => {
  const sidebar = loadTsx('../../resources/js/Components/workbench/WorkbenchSidebar.tsx', (id) => id === '@inertiajs/react' ? inertia : require(id));
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
});

test('the dedicated test issue opens without a file path while other issues still need one', () => {
  const sidebar = loadTsx('../../resources/js/Components/workbench/WorkbenchSidebar.tsx', (id) => id === '@inertiajs/react' ? inertia : require(id));
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

test('a pending connection displays the actual device code and a way to finish', () => {
  const html = renderToStaticMarkup(React.createElement(onboarding.DisconnectedOnboardingView, {
    ...baseProps, githubConnection: { deviceFlow: { userCode: 'TEST-1234', verificationUri: 'https://github.com/login/device' } },
  }));
  assert.match(html, /TEST-1234/);
  assert.match(html, /href="https:\/\/github.com\/login\/device"/);
  assert.match(html, /Check connection/);
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
