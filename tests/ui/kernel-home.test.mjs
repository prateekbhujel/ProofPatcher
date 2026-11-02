import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
const inertia = {
  Link: ({ children, href, ...props }) => React.createElement('a', { href, ...props }, children),
  Head: ({ title }) => React.createElement('title', {}, title),
  router: { post() {} },
};

/** Recursive TSX loader: resolves relative imports against the importing file. */
function createLoader(stubs = {}) {
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
    if (stubs[absPath]) {
      cache.set(absPath, { exports: stubs[absPath] });
      return stubs[absPath];
    }
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

const root = createLoader();
const HERE = fileURLToPath(import.meta.url);
const kernelPath = root.resolveFile(HERE, '../../resources/js/Pages/Kernel.tsx');
const osDesktopPath = root.resolveFile(HERE, '../../resources/js/Components/os/OsDesktop.tsx');
const windowsPath = root.resolveFile(HERE, '../../resources/js/Components/os/DesktopWindows.tsx');

// Shell test: the window manager is stubbed out so this pins the menu bar.
const shellLoader = createLoader({ [osDesktopPath]: { OsDesktop: () => null, bootLinesFor: () => [] } });
const kernel = shellLoader.load(kernelPath);
// Content tests: the real window contents with stubbed navigation.
const windows = root.load(windowsPath);

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

test('the Kernel menu bar brands the desktop and names the next step', () => {
  const html = renderToStaticMarkup(React.createElement(kernel.default, baseProps));
  assert.match(html, /ProofPatcher/);
  assert.match(html, /Kernel/);
  assert.match(html, /Not connected/);
  assert.match(html, /Connect GitHub — Link your account/);
});

test('a disconnected visitor sees banners and empty states with actions, not dead ends', () => {
  const mission = renderToStaticMarkup(
    React.createElement(windows.MissionContent, { nextStep: baseProps.nextStep, stages: baseProps.stages }),
  );
  assert.match(mission, /Start here: Connect GitHub/);
  assert.match(mission, /href="\/issues"[^>]*>.*Continue with GitHub/s);
  for (const stage of ['Intake', 'Propose', 'Verify', 'Publish']) {
    assert.match(mission, new RegExp(stage));
  }

  const engine = renderToStaticMarkup(React.createElement(windows.EngineContent, {
    connected: false,
    reposCount: 0,
    selectedRepo: null,
    openIssues: 0,
    openPulls: 0,
    provider: baseProps.provider,
    budget: baseProps.budget,
    worker: baseProps.worker,
    autoStats: baseProps.autoStats,
  }));
  assert.match(engine, /href="\/telemetry"[^>]*>.*Open telemetry/s);
  assert.match(engine, /github adapter/);
  assert.match(engine, /Not connected/);
  assert.match(engine, /GitHub · not connected/);
  assert.match(engine, /muse · provider key/);
  assert.match(engine, /Key missing/);

  const findings = renderToStaticMarkup(React.createElement(windows.FindingsContent, {
    findings: [],
    connected: false,
    lastVerification: null,
    publishedPr: null,
  }));
  assert.match(findings, /Recent review findings/);
  assert.match(findings, /Connect GitHub to enable automatic reviews/);
  assert.match(findings, /Nothing checked yet/);
});

test('the windows show pipeline state, findings, and verification honestly', () => {
  const engine = renderToStaticMarkup(React.createElement(windows.EngineContent, {
    connected: true,
    reposCount: 2,
    selectedRepo: 'acme/repo',
    openIssues: 3,
    openPulls: 1,
    provider: { ...baseProps.provider, keyReady: true },
    budget: baseProps.budget,
    worker: { isLive: true, status: 'running' },
    autoStats: { totalReviews: 2, completed: 2, failed: 0, skippedPrivate: 0, skippedBudget: 0 },
  }));
  assert.match(engine, /GitHub · 2 repos · acme\/repo/);
  assert.match(engine, /3 issues · 1 pull request loaded/);
  assert.match(engine, /Key ready/);
  assert.match(engine, /Running \(running\)/);
  assert.doesNotMatch(engine, /github adapter/);

  const mission = renderToStaticMarkup(React.createElement(windows.MissionContent, {
    nextStep: { key: 'publish', title: 'Create the draft PR', detail: 'Checks passed.', href: '/issues', cta: 'Review and publish' },
    stages: stagesTodo.map((s) => ({ ...s, state: 'done', detail: 'Flowing.' })),
  }));
  assert.match(mission, /Start here: Create the draft PR/);

  const findings = renderToStaticMarkup(React.createElement(windows.FindingsContent, {
    findings: [
      { id: 9, repository: 'acme/repo', pull_number: 12, status: 'completed', summary: 'Rounding fix looks correct.',
        risk_level: 'low', recommendation: 'approve', reviewed_at: '2026-10-31T10:00:00+00:00' },
    ],
    connected: true,
    lastVerification: { id: 'run-1', decision: 'VERIFIED', isSimulated: false,
      receiptSha256: 'd'.repeat(64), repository: 'acme/repo' },
    publishedPr: null,
  }));
  assert.match(findings, /acme\/repo#12/);
  assert.match(findings, /approve · low risk/);
  assert.match(findings, /VERIFIED/);
  assert.match(findings, /Isolated sandbox run/);
  assert.match(findings, /Receipt d{24}/);
});

test('a host verification run is labeled as local evidence only', () => {
  const findings = renderToStaticMarkup(React.createElement(windows.FindingsContent, {
    findings: [],
    connected: true,
    lastVerification: { id: 'run-2', decision: 'VERIFIED', isSimulated: true,
      receiptSha256: 'e'.repeat(64), repository: 'acme/repo' },
    publishedPr: null,
  }));
  assert.match(findings, /Host run — local evidence only, cannot authorize publishing/);
  assert.doesNotMatch(findings, /Draft PR.*on GitHub/);
});

test('the menu bar shows the connected account', () => {
  const html = renderToStaticMarkup(React.createElement(kernel.default, {
    ...baseProps,
    connected: true,
    user: { login: 'maintainer' },
  }));
  assert.match(html, /Connected as @maintainer/);
});

test('the menu bar links the primary pages and marks the current one', () => {
  const menubar = root.load(root.resolveFile(HERE, '../../resources/js/Components/os/MenuBar.tsx'));
  const html = renderToStaticMarkup(React.createElement(menubar.MenuBar, {
    center: '→ Connect GitHub — Link your account.',
    connected: false,
    userLogin: null,
    activeNav: 'issues',
  }));
  assert.match(html, /<nav aria-label="Primary"[^>]*>/);
  assert.match(html, /href="\/issues"[^>]*aria-current="page"[^>]*>.*Issues/s);
  assert.match(html, /href="\/repositories"[^>]*>.*Pull Requests/s);
  assert.match(html, /href="\/settings"[^>]*>.*Settings/s);
  assert.doesNotMatch(html, /href="\/settings"[^>]*aria-current="page"/);
});
