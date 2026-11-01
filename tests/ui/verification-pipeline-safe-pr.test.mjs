import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/Components/workbench/VerificationPipelineCard.tsx', import.meta.url), 'utf8');
const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
const mod = { exports: {} };
new Function('exports', 'require', 'module', code)(mod.exports, (id) => {
  if (id === '@inertiajs/react') return { router: { post() {} } };
  if (id === '../ui/ThinkingAnimation') return { ThinkingAnimation: () => null };
  if (id === '../ui/VerificationPipeline') return { VerificationPipeline: () => null };
  return require(id);
}, mod);
const { VerificationPipelineCard } = mod.exports;
const context = { repository: 'owner/repo', issueNumber: 1, path: 'src/OrderTotal.php', sourceSha256: 'b'.repeat(64) };
const suggestion = { path: context.path, candidate: { sha256: 'c'.repeat(64), verified: true } };
const verificationReport = { id: 'run-1', decision: 'VERIFIED', isSimulated: false,
  receipt: { sha256: 'd'.repeat(64), externallyAnchored: false }, candidateSha256: 'c'.repeat(64),
  gates: { baselineReproduced: true, negativeControlCaught: true, candidatePassed: true,
    candidateStaticSafetyPassed: true, allowlistIntegrityIntact: true } };
const props = { context, suggestion, verificationReport, isOwnedFixture: true, isDedicatedTestRepo: true,
  verifying: false, pipelineGates: [], copiedReceipt: false, copyReceipt() {}, copiedBotComment: false,
  setCopiedBotComment() {}, onVerifyCandidate() {}, publisherDisconnected: false, publishingPr: false,
  onPublishDraftPr() {}, githubConnection: { user: { login: 'owner' }, repoPulls: [
    { number: 10, state: 'open', title: 'Fix #10', head: 'unrelated', headSha: 'e'.repeat(40), base: 'main' },
    { number: 11, state: 'open', title: 'Claims #1', head: 'proofpatch/1-fix', headSha: 'f'.repeat(40), base: 'main' },
  ] }, publishedPr: null };
const render = (overrides = {}) => renderToStaticMarkup(React.createElement(VerificationPipelineCard, { ...props, ...overrides }));

test('verified candidate never infers a PR or offers an unrelated merge', () => {
  const html = render();
  assert.doesNotMatch(html, /PR #10|PR #11|Merge PR|unrelated|Claims #1/);
  assert.match(html, /Create draft PR/);
});

test('a server-returned published PR is displayed without a direct merge shortcut', () => {
  const html = render({ publishedPr: { prNumber: 23, repository: 'owner/repo',
    prUrl: 'https://github.com/owner/repo/pull/23', branch: 'proofpatch/1-fix',
    commitSha: 'a'.repeat(40), draft: true, publishedAt: '2026-11-01', receiptSha256: 'd'.repeat(64) } });
  assert.match(html, /PR #23/);
  assert.match(html, /https:\/\/github\.com\/owner\/repo\/pull\/23/);
  assert.doesNotMatch(html, /Merge PR|PR #10|PR #11/);
});
