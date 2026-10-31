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
const kernel = loadTsx('../../resources/js/Pages/Kernel.tsx', (id) => {
  if (id === '@inertiajs/react') return inertia;
  if (id === '../Components/AppLayout') return { AppLayout: ({ children }) => React.createElement('div', {}, children) };
  if (id === '../Components/os/BootScreen') return { BootScreen: () => null };
  if (id === '../Components/os/DesktopIcons') return { DesktopIcons: () => null };
  if (id === '../Components/os/KernelTerminal') return { KernelTerminal: () => null };
  if (id === '../Components/os/KernelTaskbar') return { KernelTaskbar: () => null };
  return require(id);
});

const stagesTodo = [
  { key: 'intake', title: 'Intake', detail: 'No adapter connected.', state: 'todo', href: '/issues' },
  { key: 'propose', title: 'Propose', detail: 'No proposal yet.', state: 'todo', href: '/issues' },
  { key: 'verify', title: 'Verify', detail: 'Not checked yet.', state: 'todo', href: '/issues' },
  { key: 'publish', title: 'Publish', detail: 'Nothing published.', state: 'todo', href: '/issues' },
];

const baseProps = {
  connected: false,
  user: null,
  reposCount: 0,
  selectedRepo: null,
  openIssues: 0,
  openPulls: 0,
  provider: { label: 'Baseten Inference API', model: 'meta-models/muse-spark-open-1026', keyReady: false },
  budget: { attempts: 0, maxAttempts: 10, remainingMicros: 500000, canRequest: true },
  worker: { isLive: false, status: 'offline' },
  autoStats: { totalReviews: 0, completed: 0, failed: 0, skippedPrivate: 0, skippedBudget: 0 },
  recentFindings: [],
  nextStep: { key: 'connect', title: 'Connect GitHub', detail: 'Link your account.', href: '/issues', cta: 'Continue with GitHub' },
  stages: stagesTodo,
  lastVerification: null,
  publishedPr: null,
};

test('the Kernel home tells a disconnected visitor exactly where to start', () => {
  const html = renderToStaticMarkup(React.createElement(kernel.default, baseProps));
  assert.match(html, /ProofPatcher Kernel/);
  assert.match(html, /GitHub repositories are the first adapter/);
  assert.match(html, /href="\/telemetry"[^>]*>.*Open telemetry/s);
  assert.match(html, /Start here: Connect GitHub/);
  assert.match(html, /href="\/issues"[^>]*>.*Continue with GitHub/s);
  assert.match(html, /Not connected/);
  for (const stage of ['Intake', 'Propose', 'Verify', 'Publish']) {
    assert.match(html, new RegExp(stage));
  }
  assert.match(html, /GitHub · not connected/);
  assert.match(html, /Key missing/);
  assert.match(html, /Connect GitHub to enable automatic reviews/);
  assert.match(html, /Nothing checked yet/);
});

test('the Kernel home shows pipeline state, findings, and verification honestly', () => {
  const html = renderToStaticMarkup(React.createElement(kernel.default, {
    ...baseProps,
    connected: true,
    user: { login: 'maintainer' },
    reposCount: 2,
    selectedRepo: 'acme/repo',
    openIssues: 3,
    openPulls: 1,
    provider: { ...baseProps.provider, keyReady: true },
    worker: { isLive: true, status: 'running' },
    autoStats: { totalReviews: 2, completed: 2, failed: 0, skippedPrivate: 0, skippedBudget: 0 },
    recentFindings: [
      { id: 9, repository: 'acme/repo', pull_number: 12, status: 'completed', summary: 'Rounding fix looks correct.',
        risk_level: 'low', recommendation: 'approve', reviewed_at: '2026-10-31T10:00:00+00:00' },
    ],
    nextStep: { key: 'publish', title: 'Create the draft PR', detail: 'Checks passed.', href: '/issues', cta: 'Review and publish' },
    stages: stagesTodo.map((s) => ({ ...s, state: 'done', detail: 'Flowing.' })),
    lastVerification: { id: 'run-1', decision: 'VERIFIED', isSimulated: false,
      receiptSha256: 'd'.repeat(64), repository: 'acme/repo' },
    publishedPr: null,
  }));
  assert.match(html, /Connected as @maintainer/);
  assert.match(html, /Start here: Create the draft PR/);
  assert.match(html, /GitHub · 2 repos · acme\/repo/);
  assert.match(html, /3 issues · 1 pull request loaded/);
  assert.match(html, /Key ready/);
  assert.match(html, /Running \(running\)/);
  assert.match(html, /acme\/repo#12/);
  assert.match(html, /approve · low risk/);
  assert.match(html, /VERIFIED/);
  assert.match(html, /Isolated sandbox run/);
  assert.match(html, /Receipt d{24}/);
});

test('a host verification run is labeled as local evidence only', () => {
  const html = renderToStaticMarkup(React.createElement(kernel.default, {
    ...baseProps,
    connected: true,
    nextStep: { key: 'target', title: 'Open an issue or pull request', detail: 'Pick a target.', href: '/issues', cta: 'Open the workbench' },
    lastVerification: { id: 'run-2', decision: 'VERIFIED', isSimulated: true,
      receiptSha256: 'e'.repeat(64), repository: 'acme/repo' },
  }));
  assert.match(html, /Host run — local evidence only, cannot authorize publishing/);
  assert.doesNotMatch(html, /Draft PR.*on GitHub/);
});
