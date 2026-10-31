import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
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
const inertia = {
  Link: ({ children, href, ...props }) => React.createElement('a', { href, ...props }, children),
  Head: ({ title }) => React.createElement('title', {}, title),
  router: { post() {} },
};
const logo = loadTsx('../../resources/js/Components/ui/BrandMark.tsx');
const layout = loadTsx('../../resources/js/Components/AppLayout.tsx', (id) => {
  if (id === '@inertiajs/react') return inertia;
  if (id === './ui/BrandMark') return logo;
  return require(id);
});
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

test('the workspace has no duplicate global sidebar; other pages link the Kernel home', () => {
  const body = React.createElement('p', {}, 'Page content');
  const workspace = renderToStaticMarkup(React.createElement(layout.AppLayout, { activeNav: 'issues' }, body));
  assert.match(workspace, /Page content/);
  assert.doesNotMatch(workspace, /<aside|ProofPatcher Kernel home/);
  const settings = renderToStaticMarkup(React.createElement(layout.AppLayout, { activeNav: 'settings' }, body));
  assert.match(settings, /href="\/"[^>]*aria-label="ProofPatcher Kernel home"/);
  assert.match(settings, /href="\/issues"/);
  assert.match(settings, /href="\/repositories"/);
  assert.doesNotMatch(settings, /href="\/checks"/);
  assert.match(settings, /href="\/telemetry"/);
  assert.match(settings, /href="\/settings"/);
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
  assert.match(html, /href="\/settings"/);
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
