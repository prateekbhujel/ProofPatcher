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
const telemetry = loadTsx('../../resources/js/Pages/Telemetry.tsx', (id) => {
  if (id === '@inertiajs/react') return inertia;
  if (id === '../Components/AppLayout') return { AppLayout: ({ children }) => React.createElement('div', {}, children) };
  return require(id);
});

const filled = {
  requests: { total: 14, received: 14, uncertain: 0, reserved: 0, reservedMicros: 264845 },
  tokens: { prompt: 11064, completion: 5531, total: 16595 },
  perDay: [
    { day: '2026-10-31', requests: 4, micros: 63000 },
    { day: '2026-10-30', requests: 1, micros: 15900 },
  ],
  models: [{ model: 'muse-spark-1.3', reviews: 3, promptTokens: 900, completionTokens: 150 }],
  reviews: { total: 5, completed: 3, failed: 1, skippedPrivate: 1, skippedBudget: 0 },
  feed: [
    { at: '2026-10-31T10:00:00+00:00', kind: 'review', status: 'completed', detail: 'acme/repo#1 · approve', micros: null, tokens: 350 },
    { at: '2026-10-31T09:00:00+00:00', kind: 'model_request', status: 'received', detail: 'Model request reservation', micros: 15000, tokens: 120 },
  ],
  generatedAt: '2026-10-31T12:00:00+00:00',
  ledgerFound: true,
  ledgerAvailable: true,
};

const empty = {
  requests: { total: 0, received: 0, uncertain: 0, reserved: 0, reservedMicros: 0 },
  tokens: { prompt: 0, completion: 0, total: 0 },
  perDay: [],
  models: [],
  reviews: { total: 0, completed: 0, failed: 0, skippedPrivate: 0, skippedBudget: 0 },
  feed: [],
  generatedAt: '2026-10-31T12:00:00+00:00',
  ledgerFound: false,
  ledgerAvailable: true,
};

test('telemetry shows totals, models, daily bars, and the request feed', () => {
  const html = renderToStaticMarkup(React.createElement(telemetry.default, { telemetry: filled }));
  assert.match(html, /Telemetry/);
  assert.match(html, /pre-call estimates, not provider bills/);
  assert.match(html, /11064 \/ 5531/);
  assert.match(html, /\$0\.2648/);
  assert.match(html, /muse-spark-1\.3/);
  assert.match(html, /10-31/);
  assert.match(html, /acme\/repo#1 · approve/);
  assert.match(html, /350 tok/);
  assert.match(html, /kernel:telemetry/);
  assert.doesNotMatch(html, /No request ledger found/);
});

test('telemetry without data states what is missing instead of zeros alone', () => {
  const html = renderToStaticMarkup(React.createElement(telemetry.default, { telemetry: empty }));
  assert.match(html, /No request ledger found yet/);
  assert.match(html, /No model usage recorded yet/);
  assert.match(html, /No daily activity yet/);
  assert.match(html, /Nothing recorded yet/);
});

test('web telemetry explains when the shared ledger cannot be shown', () => {
  const html = renderToStaticMarkup(React.createElement(telemetry.default, { telemetry: { ...empty, ledgerAvailable: false } }));
  assert.match(html, /shared ledger cannot be scoped to this account/);
  assert.match(html, /Available in the local terminal/);
  assert.doesNotMatch(html, /No request ledger found yet/);
});
