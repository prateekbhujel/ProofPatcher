import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
function component(path, customRequire = require) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
  const module = { exports: {} };
  new Function('exports', 'require', 'module', code)(module.exports, customRequire, module);
  return module.exports;
}
const { IssueHeaderCard } = component('../../resources/js/Components/workbench/IssueHeaderCard.tsx');
const { GuidedStepBar } = component('../../resources/js/Components/workbench/GuidedStepBar.tsx');
const { PrReviewCommentsTab } = component('../../resources/js/Components/repositories/PrReviewCommentsTab.tsx',
  (id) => id === '@inertiajs/react' ? { router: { post() {} } } : require(id));
const { PrHeaderCard } = component('../../resources/js/Components/repositories/PrHeaderCard.tsx',
  (id) => id === '@inertiajs/react' ? { router: { post() {} } } : require(id));
const { TaskComposerFooter } = component('../../resources/js/Components/workbench/TaskComposerFooter.tsx',
  (id) => id === '../VoiceDictationButton' ? { VoiceDictationButton: () => null } : require(id));
const { AutoReviewPanel } = component('../../resources/js/Components/settings/AutoReviewPanel.tsx');
const render = (type, props) => renderToStaticMarkup(React.createElement(type, props));

const context = { title: 'An issue', body: 'A description', issueNumber: 1, issueUrl: 'https://github.com/owner/repo/issues/1',
  path: 'src/example.php', source: '<?php', sourceSha256: 'a'.repeat(64) };

test('a dedicated private test issue is not described as public or as a synthetic issue', () => {
  const html = render(IssueHeaderCard, { context, isOwnedFixture: true, isDedicatedTestRepo: true, detectedIssueMedia: [] });
  assert.match(html, /GitHub issue/);
  assert.doesNotMatch(html, /Public GitHub Issue|Example issue/);
  assert.match(html, /<summary[^>]*>View source file<\/summary>/);
});

test('issue attachments are not represented as being understood by Muse', () => {
  const html = render(IssueHeaderCard, { context, isOwnedFixture: false, isDedicatedTestRepo: false,
    detectedIssueMedia: [{ alt: 'Screenshot', url: 'https://example.com/image.png', isGif: false }] });
  assert.match(html, /Muse cannot read these attachments yet/);
});

test('progress does not show checks as done when a reported gate failed', () => {
  const report = { decision: 'VERIFIED', gates: { baselineReproduced: true, negativeControlCaught: true,
    candidatePassed: false, candidateStaticSafetyPassed: true, allowlistIntegrityIntact: true } };
  const html = render(GuidedStepBar, { context, suggestion: {}, verificationReport: report, hasPublishedPr: false });
  assert.match(html, /aria-current="step"[^>]*>.*Checks/);
});

test('draft comments remain visible and unsupported full review submission is labeled', () => {
  const props = { drafts: [{ id: '1', path: 'a.php', line: 4, side: 'RIGHT', comment: 'Please check this.' }],
    commentApprovals: {}, commentToPost: null, postingComment: false, clearDraftsProcessing: false,
    githubConnection: null, setCommentToPost() {}, setPostingComment() {}, handleClearAllDrafts() {} };
  const html = render(PrReviewCommentsTab, props);
  assert.match(html, /Please check this/);
  assert.match(html, /Posting it is not available/);
  assert.match(html, /Coming soon/);
  assert.doesNotMatch(html, /proofpatcher\[bot\]|Post comment<\/button>/);
  const approved = render(PrReviewCommentsTab, { ...props, commentApprovals: { 1: 'digest' }, githubConnection: { user: { login: 'owner' } } });
  assert.match(approved, /as @owner/);
  assert.match(approved, /Post comment/);
});

test('automatic reviews show the real worker state and only enable public repositories', () => {
  const repositories = [
    { fullName: 'owner/public', isPrivate: false },
    { fullName: 'owner/private', isPrivate: true },
  ];
  const props = { connected: true, repositories, pendingRepo: null, error: null, onToggle() {}, onRefresh() {},
    autoReview: { worker: { isLive: false, status: 'offline' }, settings: {}, publicOnly: true } };
  const html = render(AutoReviewPanel, props);
  assert.match(html, /Autopilot offline/);
  assert.match(html, /php artisan review:poll --daemon/);
  assert.match(html, /Automatic review for owner\/private[^>]*disabled/);
  assert.match(html, /Automatic comments require a separate opt-in/);
  const running = render(AutoReviewPanel, { ...props, autoReview: {
    worker: { isLive: true, status: 'running' },
    settings: {
      'owner/public': { repository: 'owner/public', enabled: true },
      'owner/private': { repository: 'owner/private', enabled: true },
    },
    publicOnly: true,
  } });
  assert.match(running, /Autopilot running/);
  assert.match(running, /Automatic review for owner\/public[^>]*checked/);
  assert.match(running, /Off \(Private\)/);
  assert.doesNotMatch(running, /Automatic review for owner\/private[^>]*checked/);
  assert.doesNotMatch(running, /Start the local worker/);
});

test('an uncertain automatic comment is never presented as not posted', () => {
  const props = { connected: true, repositories: [{ fullName: 'owner/public', isPrivate: false }],
    pendingRepo: null, error: null, onToggle() {}, onRefresh() {}, autoReview: {
      worker: { isLive: false, status: 'offline' }, settings: {}, publicOnly: true,
      recentResults: [{ id: 1, repository: 'owner/public', pull_number: 3, status: 'completed',
        published_status: 'publication_unknown', reviewed_at: null }],
    } };
  const html = render(AutoReviewPanel, props);
  assert.match(html, /Comment outcome unknown/);
  assert.doesNotMatch(html, /Not posted to GitHub/);
});

test('pull request header renders Merge and Close controls for open PRs and disables Merge for drafts', () => {
  const basePull = {
    repository: 'owner/repo',
    number: 7,
    title: 'Fix zero-quantity order total',
    state: 'open',
    head: 'proofpatch/1-zero-quantity-items-66b1eb',
    base: 'main',
    headSha: 'a'.repeat(40),
    url: 'https://github.com/owner/repo/pull/7',
    isPrivate: false,
    isDraft: false,
    files: [{ name: 'src/OrderTotal.php', additions: 2, deletions: 1 }],
  };

  const openHtml = render(PrHeaderCard, { pull: basePull, triage: null, onViewTriage() {} });
  assert.match(openHtml, />Merge<\/span>/);
  assert.match(openHtml, />Close<\/span>/);

  const draftHtml = render(PrHeaderCard, { pull: { ...basePull, isDraft: true }, triage: null, onViewTriage() {} });
  assert.match(draftHtml, /disabled=""[^>]*>.*Draft · cannot merge/);
  assert.match(draftHtml, />Close<\/span>/);

  const mergedHtml = render(PrHeaderCard, { pull: { ...basePull, state: 'merged' }, triage: null, onViewTriage() {} });
  assert.doesNotMatch(mergedHtml, />Merge<\/span>/);
  assert.doesNotMatch(mergedHtml, />Close<\/span>/);
});

test('TaskComposerFooter renders spacious multi-line textarea with visible label and field-sizing content', () => {
  const html = render(TaskComposerFooter, {
    draftValue: 'Reproduced with quantity=0',
    onDraftChange() {},
    onSaveDraft() {},
    isSaving: false,
    draft: null,
  });
  assert.match(html, /<textarea[^>]*id="review-draft-note"[^>]*rows="4"/);
  assert.match(html, /\[field-sizing:content\]/);
  assert.match(html, /Maintainer Review Note/);
});

const { ThinkingAnimation } = component('../../resources/js/Components/ui/ThinkingAnimation.tsx');
const { VerificationPipeline } = component('../../resources/js/Components/ui/VerificationPipeline.tsx');
const { VerificationPipelineCard } = component('../../resources/js/Components/workbench/VerificationPipelineCard.tsx',
  (id) => {
    if (id === '@inertiajs/react') return { router: { post() {} } };
    if (id === '../ui/ThinkingAnimation') return { ThinkingAnimation };
    if (id === '../ui/VerificationPipeline') return { VerificationPipeline };
    return require(id);
  });
const { loadTsModule } = await import('./helpers/loadTsModule.mjs');
const budgetCopy = loadTsModule(new URL('../../resources/js/Components/ui/budgetCopy.ts', import.meta.url));
const { PrReviewTab } = component('../../resources/js/Components/repositories/PrReviewTab.tsx',
  (id) => (id === '../ui/budgetCopy' ? budgetCopy : require(id)));

test('automatic reviews list recent findings with local-only provenance', () => {
  const repositories = [{ fullName: 'owner/public', isPrivate: false }];
  const base = { connected: true, repositories, pendingRepo: null, error: null, onToggle() {}, onRefresh() {},
    autoReview: { worker: { isLive: true, status: 'running' }, settings: {}, publicOnly: true } };
  const empty = render(AutoReviewPanel, base);
  assert.match(empty, /Recent findings/);
  assert.match(empty, /No automatic reviews yet/);

  const disconnected = render(AutoReviewPanel, { ...base, connected: false });
  assert.match(disconnected, /Connect GitHub to choose repositories/);
  assert.doesNotMatch(disconnected, /Recent findings/);
  assert.doesNotMatch(disconnected, /reviewed ·/);

  const filled = render(AutoReviewPanel, { ...base,
    autoReview: { ...base.autoReview,
      stats: { totalReviews: 2, completed: 1, failed: 0, skippedPrivate: 1, skippedBudget: 0 },
      recentResults: [
        { id: 7, repository: 'owner/public', pull_number: 42, status: 'completed', summary: 'Rounding fix looks correct.',
          risk_level: 'low', recommendation: 'approve', model: 'meta-models/muse-spark-open-1026',
          published_status: 'local_only', reviewed_at: '2026-10-31T10:00:00+00:00', created_at: '2026-10-31T10:00:00+00:00' },
        { id: 6, repository: 'owner/public', pull_number: 41, status: 'skipped_private', summary: null,
          risk_level: null, recommendation: null, model: null, published_status: 'local_only',
          reviewed_at: null, created_at: '2026-10-31T09:00:00+00:00' },
      ] } });
  assert.match(filled, /1 reviewed/);
  assert.match(filled, /owner\/public#42/);
  assert.match(filled, /approve · low risk/);
  assert.match(filled, /Rounding fix looks correct/);
  assert.match(filled, /Skipped \(private\)/);
  assert.match(filled, /Stored locally · Not posted to GitHub/);
});

test('review provenance distinguishes automatic and manual Muse reviews', () => {
  const base = { reviewPreview: null, isPrivate: false, reviewApproved: false, reviewProcessing: false,
    budget: null, modelKeyAvailable: true, copiedReviewDigest: false, setReviewApproved() {}, copyText() {},
    handleReviewSubmit() {}, setReviewFormData() {}, reviewError: null };
  const review = { unverified: true, model: 'muse-spark-1.3', reviewedAt: '2026-10-31T10:00:00+00:00',
    inputDigest: 'a'.repeat(64), summary: 'Advisory summary.', riskLevel: 'low', recommendation: 'approve',
    fileFindings: [], usage: { prompt_tokens: 10, completion_tokens: 5 } };
  const automatic = render(PrReviewTab, { ...base, review: { ...review, source: 'automatic' } });
  assert.match(automatic, /Automatic review/);
  assert.match(automatic, /Not posted to GitHub/);
  const manual = render(PrReviewTab, { ...base, review });
  assert.match(manual, /Manual review/);
});

test('verification states unavailable checks and blocked draft PRs explicitly', () => {
  const base = { context: { repository: 'owner/repo', issueNumber: 9, path: 'src/Other.php', sourceSha256: 'b'.repeat(64) },
    verifying: false, pipelineGates: null, copiedReceipt: false, copyReceipt() {}, copiedBotComment: false,
    setCopiedBotComment() {}, onVerifyCandidate() {}, githubConnection: null, publishedPr: null,
    publisherDisconnected: true, publishingPr: false, onPublishDraftPr() {} };
  const suggestion = { path: 'src/Other.php', find: 'a', replace: 'b', explanation: 'Try this.',
    model: 'muse-spark-1.3', candidate: { sha256: 'c'.repeat(64), verified: false } };

  const generalRepo = render(VerificationPipelineCard, { ...base, suggestion, verificationReport: null,
    isOwnedFixture: false, isDedicatedTestRepo: false });
  assert.match(generalRepo, /Checks unavailable for this repository/);
  assert.match(generalRepo, /advisory and unverified/);

  const verified = { id: '20261031T100000Z-abcdef123456', decision: 'VERIFIED', isSimulated: true,
    receipt: { sha256: 'd'.repeat(64), externallyAnchored: false }, candidateSha256: 'c'.repeat(64),
    gates: { baselineReproduced: true, negativeControlCaught: true, candidatePassed: true,
      candidateStaticSafetyPassed: true, allowlistIntegrityIntact: true } };
  const simulated = render(VerificationPipelineCard, { ...base, suggestion, verificationReport: verified,
    pipelineGates: [], isOwnedFixture: true, isDedicatedTestRepo: true });
  assert.match(simulated, /Draft PR unavailable for this run/);
  assert.match(simulated, /isolated sandbox/);
  assert.doesNotMatch(simulated, /Create draft PR/);

  const genuine = render(VerificationPipelineCard, { ...base, suggestion,
    verificationReport: { ...verified, isSimulated: false }, pipelineGates: [],
    isOwnedFixture: true, isDedicatedTestRepo: true,
    githubConnection: { repoPulls: [], user: { login: 'maintainer' } }, publisherDisconnected: false });
  assert.doesNotMatch(genuine, /Draft PR unavailable for this run/);
  assert.match(genuine, /Create draft PR/);
});

test('issue header shows report completeness with missing checklist', () => {
  const base = { context, isOwnedFixture: false, isDedicatedTestRepo: false, detectedIssueMedia: [] };
  const without = render(IssueHeaderCard, base);
  assert.doesNotMatch(without, /Complete report|Needs info/);

  const needsInfo = {
    score: 40, verdict: 'needs_info',
    checks: [
      { key: 'title', label: 'Descriptive title', passed: true, detail: 'Title summarizes the problem.' },
      { key: 'repro', label: 'Reproduction steps', passed: false, detail: 'No reproduction steps found.' },
    ],
    missing: ['Reproduction steps'],
    questions: ['What are the exact steps to reproduce this, starting from a clean checkout?'],
  };
  const flagged = render(IssueHeaderCard, { ...base, completeness: needsInfo });
  assert.match(flagged, /Needs info · 40/);
  assert.match(flagged, /Missing: Reproduction steps/);
  assert.match(flagged, /Worth asking the reporter:/);
  assert.match(flagged, /exact steps to reproduce/);

  const complete = {
    score: 100, verdict: 'complete',
    checks: [{ key: 'title', label: 'Descriptive title', passed: true, detail: 'Title summarizes the problem.' }],
    missing: [],
    questions: [],
  };
  const done = render(IssueHeaderCard, { ...base, completeness: complete });
  assert.match(done, /Complete report · 100/);
  assert.match(done, /What was checked/);
  assert.doesNotMatch(done, /Worth asking the reporter/);
});
