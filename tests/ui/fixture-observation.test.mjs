import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { transformSync } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/Components/workbench/FixtureObservationCard.tsx', import.meta.url), 'utf8');
const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
const module = { exports: {} };
new Function('exports', 'require', 'module', code)(module.exports, require, module);
const { FixtureObservationCard } = module.exports;
const candidate = { candidate: { sha256: 'abc' } };
const passed = { status: 'passed', scope: 'finite_local_fixture', candidateSha256: 'abc', harnessSha256: 'test',
  baseline: 'fixture_failed', negative: 'fixture_failed', candidate: 'fixture_passed',
  calls: { baseline: 3, negative: 3, candidate: 3 }, canPublish: true };
const html = (extra = {}) => renderToStaticMarkup(React.createElement(FixtureObservationCard, {
  suggestion: candidate, observation: null, observing: false, publishing: false,
  onObserve() {}, onPublish() {}, ...extra,
}));

test('passes are explicitly limited to fixture cases', () => {
  const rendered = html({ observation: passed });
  assert.match(rendered, /Fixture checks passed/);
  assert.match(rendered, /do not prove the patch is generally correct or safe/);
  assert.match(rendered, /Create draft PR/);
  assert.doesNotMatch(rendered, /VERIFIED/);
});

test('stale, failed, or unauthorized observations cannot show a publish action', () => {
  assert.doesNotMatch(html({ observation: { ...passed, candidateSha256: 'other' } }), /Create draft PR/);
  assert.doesNotMatch(html({ observation: { ...passed, status: 'failed' } }), /Create draft PR/);
  assert.doesNotMatch(html({ observation: { ...passed, canPublish: false } }), /Create draft PR/);
  assert.doesNotMatch(html({ observation: { ...passed, negative: 'fixture_passed' } }), /Create draft PR/);
  assert.doesNotMatch(html({ observation: { ...passed, negative: 'fixture_passed' } }), /Fixture checks passed/);
  assert.doesNotMatch(html({ observation: { ...passed, calls: { baseline: 3, negative: 0, candidate: 3 } } }), /Create draft PR/);
});

test('a completed publication is linked rather than offered again', () => {
  const rendered = html({ observation: passed, publishedUrl: 'https://github.com/owner/repo/pull/1' });
  assert.match(rendered, /Open existing draft on GitHub/);
  assert.doesNotMatch(rendered, /Create draft PR/);
  assert.match(html({ publishedUrl: 'https://github.com/owner/repo/pull/1' }), /Open existing draft on GitHub/);
});
