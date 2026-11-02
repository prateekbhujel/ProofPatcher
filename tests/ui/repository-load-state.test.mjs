import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/Components/repositories/PrDashboardView.tsx', import.meta.url), 'utf8');
const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
const mod = { exports: {} };
const localRequire = (id) => {
  if (id === './PrInspectForm') return { PrInspectForm: () => null };
  if (id === '../console/primitives') return { StateChip: () => null };
  return require(id);
};
new Function('exports', 'require', 'module', code)(mod.exports, localRequire, mod);

function render(connection) {
  return renderToStaticMarkup(React.createElement(mod.exports.PrDashboardView, {
    githubConnection: { repositories: [{ fullName: 'owner/repo' }], repoPulls: [], ...connection },
    handleSelectRepository() {}, handleCaptureGitHubPull() {}, inspectUrl: '', setInspectUrl() {},
    handleInspectSubmit() {}, inspectProcessing: false,
  }));
}

test('restored selection shows a load action rather than a false empty result', () => {
  const html = render({ selectedRepo: 'owner/repo', repoPullsLoaded: false });
  assert.match(html, /Pull requests not loaded/);
  assert.match(html, /Load pull requests/);
  assert.doesNotMatch(html, /No pull requests found/);
  assert.doesNotMatch(html, /No pull requests are currently open/);
});

test('a completed empty fetch shows a real empty result', () => {
  const html = render({ selectedRepo: 'owner/repo', repoPullsLoaded: true });
  assert.match(html, /No pull requests found in owner\/repo/);
  assert.doesNotMatch(html, /Pull requests not loaded/);
});

test('partial fetches warn instead of claiming an empty repository', () => {
  const html = render({ selectedRepo: 'owner/repo', repoPullsLoaded: true, repoPullsStatus: { errors: ['unavailable'] } });
  assert.match(html, /Some pull requests could not be loaded/);
  assert.match(html, /The list is incomplete/);
  assert.doesNotMatch(html, /No pull requests found in owner\/repo/);
});

test('no selection prompts for a repository rather than using the first grant', () => {
  const html = render({ selectedRepo: null, repoPullsLoaded: false });
  assert.match(html, /Choose a repository to load its pull requests/);
  assert.doesNotMatch(html, /No pull requests found in owner\/repo/);
});
