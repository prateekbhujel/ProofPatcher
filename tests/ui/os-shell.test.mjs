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
const withInertia = (id) => (id === '@inertiajs/react' ? inertia : require(id));

const boot = loadTsx('../../resources/js/Components/os/BootScreen.tsx');
const terminal = loadTsx('../../resources/js/Components/os/KernelTerminal.tsx');
const taskbar = loadTsx('../../resources/js/Components/os/KernelTaskbar.tsx');
const desktop = loadTsx('../../resources/js/Components/os/DesktopIcons.tsx', withInertia);

test('the boot screen shows every line and a skip hint in static markup', () => {
  const html = renderToStaticMarkup(React.createElement(boot.BootScreen, {
    version: 'v0.3',
    lines: ['[ok] adapter    github', '[ok] muse       meta-x', '[--] autopilot  offline'],
  }));
  assert.match(html, /ProofPatcher Kernel v0\.3 — boot/);
  assert.match(html, /\[ok\] adapter    github/);
  assert.match(html, /\[ok\] muse       meta-x/);
  assert.match(html, /\[--\] autopilot  offline/);
  assert.match(html, /click anywhere to skip/);
});

test('the kernel terminal renders its transcript, prompt, and caret', () => {
  const html = renderToStaticMarkup(React.createElement(terminal.KernelTerminal, {
    title: 'kernel — console',
    lines: [
      { text: '$ proofpatcher status', tone: 'muted' },
      { text: '✓ adapter   github · 2 repos', tone: 'ok' },
      { text: '! muse      key missing', tone: 'warn' },
      { text: '→ next      Connect GitHub — Link it.', tone: 'cmd' },
    ],
  }));
  assert.match(html, /kernel — console/);
  assert.match(html, /\$ proofpatcher status/);
  assert.match(html, /✓ adapter   github · 2 repos/);
  assert.match(html, /! muse      key missing/);
  assert.match(html, /→ next      Connect GitHub/);
  assert.match(html, /proofpatcher/);
  assert.match(html, /pp-caret/);
});

test('the taskbar shows worker state, counts, and budget', () => {
  const html = renderToStaticMarkup(React.createElement(taskbar.KernelTaskbar, {
    connected: true,
    userLogin: 'maintainer',
    reposCount: 2,
    openIssues: 3,
    openPulls: 1,
    findingsCount: 4,
    budgetText: '$0.42 left',
    workerLive: true,
    workerStatus: 'running',
  }));
  assert.match(html, /ProofPatcher Kernel/);
  assert.match(html, /@maintainer/);
  assert.match(html, /2 repos/);
  assert.match(html, /3 issues/);
  assert.match(html, /1 pull/);
  assert.match(html, /4 findings/);
  assert.match(html, /\$0\.42 left/);
  assert.match(html, /autopilot running/);
  assert.match(html, /href="\/settings"[^>]*aria-label="Open Settings"/);
});

test('the taskbar is honest when disconnected and offline', () => {
  const html = renderToStaticMarkup(React.createElement(taskbar.KernelTaskbar, {
    connected: false,
    userLogin: null,
    reposCount: 0,
    openIssues: 0,
    openPulls: 0,
    findingsCount: 0,
    budgetText: 'budget n/a',
    workerLive: false,
    workerStatus: 'offline',
  }));
  assert.match(html, /not connected/);
  assert.match(html, /autopilot offline/);
  assert.match(html, /budget n\/a/);
});

test('desktop icons link to the existing pages', () => {
  const html = renderToStaticMarkup(React.createElement(desktop.DesktopIcons, {}));
  for (const href of ['/issues', '/repositories', '/telemetry', '/terminal', '/settings', '/docs']) {
    assert.match(html, new RegExp(`href="${href}"`));
  }
  assert.match(html, /Issues &amp; Tasks/);
  assert.match(html, /Pull Requests/);
  assert.match(html, /Terminal/);
});

test('the taskbar never shrinks its status items', () => {
  const html = renderToStaticMarkup(React.createElement(taskbar.KernelTaskbar, {
    connected: true,
    userLogin: 'maintainer',
    reposCount: 68,
    openIssues: 0,
    openPulls: 0,
    findingsCount: 3,
    budgetText: '$0.22 left',
    workerLive: false,
    workerStatus: 'offline',
  }));
  assert.match(html, /autopilot offline/);
  assert.ok(!html.match(/<span>\d+ (repo|issue|pull|finding)/));
});

test('the taskbar shows placeholders while status loads', () => {
  const html = renderToStaticMarkup(React.createElement(taskbar.KernelTaskbar, {
    connected: false,
    userLogin: null,
    reposCount: 0,
    openIssues: 0,
    openPulls: 0,
    findingsCount: 0,
    budgetText: '···',
    workerLive: false,
    workerStatus: '···',
    loading: true,
  }));
  assert.match(html, /status loading/);
  assert.match(html, /···/);
  assert.doesNotMatch(html, /not connected/);
  assert.doesNotMatch(html, /autopilot offline/);
});
