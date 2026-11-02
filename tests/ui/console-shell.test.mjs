import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const require = createRequire(import.meta.url);
const ts = require('typescript');

const inertiaBase = {
  Link: ({ children, href, ...props }) => React.createElement('a', { href, ...props }, children),
  Head: ({ title }) => React.createElement('title', {}, title),
  router: { post() {} },
};

function createLoader(pageProps = { embed: false }) {
  const cache = new Map();
  function resolveFile(from, spec) {
    const base = new URL(spec, `file://${from}`).pathname;
    const fs = require('node:fs');
    for (const candidate of [base, `${base}.tsx`, `${base}.ts`, `${base}/index.tsx`]) {
      try {
        if (fs.statSync(candidate).isFile()) return candidate;
      } catch { /* try next */ }
    }
    return base;
  }
  function load(absPath) {
    if (cache.has(absPath)) return cache.get(absPath);
    const source = require('node:fs').readFileSync(absPath, 'utf8');
    const { outputText } = ts.transpileModule(source, {
      compilerOptions: { jsx: ts.JsxEmit.React, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
      fileName: absPath,
    });
    const mod = { exports: {} };
    cache.set(absPath, mod.exports);
    const inertia = {
      ...inertiaBase,
      usePage: () => ({ props: pageProps }),
    };
    const localRequire = (id) => {
      if (id === '@inertiajs/react') return inertia;
      if (id.startsWith('.')) return load(resolveFile(absPath, id));
      return require(id);
    };
    new Function('exports', 'require', 'module', outputText)(mod.exports, localRequire, mod);
    cache.set(absPath, mod.exports);
    return mod.exports;
  }
  return { load, resolveFile };
}

const HERE = fileURLToPath(import.meta.url);
const root = createLoader();
const layout = root.load(root.resolveFile(HERE, '../../resources/js/Components/AppLayout.tsx'));
const kernelPage = root.load(root.resolveFile(HERE, '../../resources/js/Pages/Kernel.tsx'));
const signIn = root.load(root.resolveFile(HERE, '../../resources/js/Components/ui/SignInWithGitHubButton.tsx'));

test('the console rail links every section and marks the current page', () => {
  const html = renderToStaticMarkup(React.createElement(layout.AppLayout, { activeNav: 'issues' }, React.createElement('p', {}, 'Body')));
  assert.match(html, /aria-label="Console"/);
  assert.match(html, /href="\/issues"[^>]*aria-current="page"/);
  assert.match(html, /href="\/repositories"/);
  assert.match(html, /href="\/settings"/);
  assert.match(html, /Body/);
});

test('the console is honest while status loads', () => {
  const html = renderToStaticMarkup(React.createElement(layout.AppLayout, { activeNav: 'settings' }, React.createElement('p', {}, 'Body')));
  assert.match(html, /loading…/);
  assert.match(html, /···/);
  assert.doesNotMatch(html, /not connected/);
  assert.doesNotMatch(html, /autopilot live/);
});

test('embedded pages render bare content with no console chrome', () => {
  const embedLoader = createLoader({ embed: true });
  const embedLayout = embedLoader.load(embedLoader.resolveFile(HERE, '../../resources/js/Components/AppLayout.tsx'));
  const html = renderToStaticMarkup(React.createElement(embedLayout.AppLayout, { activeNav: 'docs' }, React.createElement('p', {}, 'Bare')));
  assert.match(html, /Bare/);
  assert.doesNotMatch(html, /aria-label="Console"/);
  assert.doesNotMatch(html, /aria-label="Primary"/);
});

const homeProps = {
  connected: true,
  user: { login: 'maintainer' },
  reposCount: 2,
  selectedRepo: 'acme/repo',
  openIssues: 1,
  openPulls: 1,
  provider: { label: 'Baseten Inference API', model: 'muse-spark', keyReady: true },
  budget: { attempts: 3, maxAttempts: 25, remainingMicros: 240000, canRequest: true },
  hari: {
    frozen: true,
    abilities: [{ name: 'proofpatch-verify', version: 1, provides: ['verify.gate'], borrows: {}, needs: ['model'] }],
    skills: [{ id: 'verify.gate', versions: [1], capabilities: ['model'], describe: 'Runs the gates.' }],
  },
  autopilotCaps: { remaining: 22, maxAttempts: 25 },
  worker: { isLive: false, status: 'offline' },
  autoStats: { total: 1, completed: 1, failed: 0, skippedPrivate: 0, skippedBudget: 0, enabledRepositories: [] },
  recentFindings: [{ id: 1, repository: 'acme/repo', pull_number: 7, status: 'completed', summary: 'ok', risk_level: 'low', recommendation: null, reviewed_at: null }],
  stages: [{ key: 'intake', title: 'Intake', detail: 'Repo ready.', href: '/issues', state: 'done' }],
  nextStep: { key: 'target', title: 'Open an issue or pull request', detail: 'Pick a target from the workbench.', href: '/issues', cta: 'Open the workbench' },
  lastVerification: null,
  publishedPr: null,
};

test('the console home shows pipeline, engine and hari panels', () => {
  const html = renderToStaticMarkup(React.createElement(kernelPage.default, homeProps));
  assert.match(html, /next: Open an issue or pull request/);
  assert.match(html, /Open the workbench/);
  assert.match(html, /3\/25 req/);
  assert.match(html, /proofpatch-verify/);
  assert.match(html, /verify\.gate/);
  assert.match(html, /frozen/);
  assert.match(html, /acme\/repo#7/);
  assert.doesNotMatch(html, /app window|start menu|Mission control/);
});

test('the home next step sends a signed-out operator to sign in', () => {
  const html = renderToStaticMarkup(React.createElement(kernelPage.default, {
    ...homeProps,
    connected: false,
    user: null,
    hari: null,
    nextStep: { key: 'connect', title: 'Connect GitHub', detail: 'Link your account.', href: '/issues', cta: 'Continue with GitHub' },
  }));
  assert.match(html, /next: Connect GitHub/);
  assert.match(html, /Continue with GitHub/);
  assert.match(html, /registry unavailable/);
});

test('the sign-in button links to the web flow with its return page', () => {
  const html = renderToStaticMarkup(React.createElement(signIn.SignInWithGitHubButton, { redirectTo: '/settings' }));
  assert.match(html, /href="\/github\/authorize\?redirect_to=%2Fsettings"/);
  assert.match(html, /Sign in with GitHub/);
  assert.doesNotMatch(html, /client_secret|access_token/);
});
