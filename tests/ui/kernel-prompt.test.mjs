import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { transformSync } from 'esbuild';

// Run the real hook with deterministic state/router doubles; no browser,
// provider calls, repository mutation or account data is involved.
const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/hooks/useKernelPrompt.ts', import.meta.url), 'utf8');
const { code } = transformSync(source, { loader: 'ts', format: 'cjs' });
const calls = [];
let feedback = null;
const router = {
  post: (...args) => calls.push(['post', ...args]),
  visit: (...args) => calls.push(['visit', ...args]),
};
const mod = { exports: {} };
new Function('exports', 'require', 'module', code)(mod.exports, (id) => {
  if (id === 'react') return { useState: (initial) => [initial, (value) => { feedback = value; }] };
  if (id === '@inertiajs/react') return { router };
  return require(id);
}, mod);
const { useKernelPrompt } = mod.exports;
const hook = (extra = {}) => useKernelPrompt({
  githubConnection: { selectedRepo: 'owner/repo', repoPulls: [{ number: 10, state: 'open', title: 'Unrelated #10', head: 'other', headSha: 'a'.repeat(40) }] },
  context: { issueNumber: 1, repository: 'owner/repo' },
  preview: { digest: 'digest', approvalToken: 'token' },
  isDedicatedTestRepo: true,
  isOwnedFixture: false,
  setPublishingPr() {}, setVerifying() {}, setSending() {}, setViewMode() {},
  ...extra,
});
const reset = () => { calls.length = 0; feedback = null; };

test('no unrelated, ambiguous or missing-context PR is presented as selected', () => {
  reset();
  assert.equal(hook().activeOpenPull, null);
  assert.equal(hook({ context: null }).activeOpenPull, null);
  assert.equal(hook({ githubConnection: { selectedRepo: 'owner/repo', repoPulls: [{ number: 3, state: 'open', title: 'Fix #1', head: 'proofpatch/1-fix', headSha: 'a'.repeat(40) }] } }).activeOpenPull, null);
});

test('proposal command never makes an implicit offline or private provider request', () => {
  reset();
  hook().executePrompt('fix this issue');
  assert.deepEqual(calls, []);
  assert.match(feedback, /choose a provider and approve/);
});

test('checks and publication feedback describe requests, never a successful result', () => {
  reset();
  hook().executePrompt('verify');
  assert.equal(calls[0][1], '/issues/verify');
  assert.equal(feedback, 'Requesting checks…');
  reset();
  hook().executePrompt('create draft pr');
  assert.equal(calls[0][1], '/issues/publish-pr');
  assert.equal(feedback, 'Submitting draft PR request…');
});

test('merge command opens the PR list without posting a merge', () => {
  reset();
  hook().executePrompt('merge #10');
  assert.deepEqual(calls, [['visit', '/repositories']]);
});
