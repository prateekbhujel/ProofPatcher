import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);

function loadTsxModule(relativePath, customRequire = require) {
  const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(mod.exports, customRequire, mod);
  return { exports: mod.exports, source };
}

const { exports: themeModule } = loadTsxModule('../../resources/js/Components/ThemeToggle.tsx');
const { exports: voiceModule, source: voiceSource } = loadTsxModule('../../resources/js/Components/VoiceDictationButton.tsx');
const { exports: thinkingModule } = loadTsxModule('../../resources/js/Components/ui/ThinkingAnimation.tsx');

// Mock @inertiajs/react for SSR rendering of TaskCardSection
const inertiaMock = {
  Link: ({ children, href, ...rest }) => React.createElement('a', { href, ...rest }, children),
  useForm: (initial) => ({
    data: initial,
    setData: () => {},
    post: () => {},
    processing: false,
    reset: () => {},
  }),
  router: {
    post: () => {},
  },
};

const { exports: alertBannerModule } = loadTsxModule(
  '../../resources/js/Components/ui/AlertBanner.tsx',
  (id) => (id === '@inertiajs/react' ? inertiaMock : require(id)),
);
const { loadTsModule } = await import('./helpers/loadTsModule.mjs');
const budgetCopy = loadTsModule(new URL('../../resources/js/Components/ui/budgetCopy.ts', import.meta.url));
const dateFormat = loadTsModule(new URL('../../resources/js/Components/ui/date.ts', import.meta.url));
const { exports: repoPickerModule } = loadTsxModule('../../resources/js/Components/workbench/RepoSearchPicker.tsx');

const consoleShared = loadTsModule(new URL('../../resources/js/Components/console/shared.ts', import.meta.url));
const { exports: taskCardModule, source: taskCardSource } = loadTsxModule(
  '../../resources/js/Components/TaskCardSection.tsx',
  (id) => {
    if (id === '@inertiajs/react') return inertiaMock;
    if (id === './VoiceDictationButton') return voiceModule;
    if (id === './workbench/RepoSearchPicker') return repoPickerModule;
    if (id === './console/shared') return consoleShared;
    return require(id);
  }
);

test('applyThemePreference and readStoredThemePreference default cleanly to light and resolve system scheme', () => {
  assert.equal(themeModule.readStoredThemePreference(), 'light');
  assert.equal(themeModule.resolveSystemScheme(), 'light');
  assert.equal(themeModule.applyThemePreference('light'), 'light');
  assert.equal(themeModule.applyThemePreference('dark'), 'dark');
  assert.equal(themeModule.applyThemePreference('system'), 'light');
});

test('ThemeToggle renders accessible radiogroup with Light, Dark, and System options', () => {
  const html = renderToStaticMarkup(React.createElement(themeModule.ThemeToggle, { compact: true }));
  assert.match(html, /role="radiogroup"/);
  assert.match(html, /aria-label="Color theme"/);
  assert.match(html, />Light</);
  assert.match(html, />Dark</);
  assert.match(html, />System</);
  assert.match(html, /role="radio"[^>]*aria-checked="true"[^>]*title="Light theme"/);
});

test('app.blade.php includes color-scheme meta and synchronous FOUC prevention script defaulting to light', () => {
  const blade = readFileSync(new URL('../../resources/views/app.blade.php', import.meta.url), 'utf8');
  assert.match(blade, /<meta name="color-scheme" content="light dark">/);
  assert.match(blade, /localStorage\.getItem\('proofpatch-theme'\)/);
  assert.match(blade, /if \(pref !== 'light' && pref !== 'dark' && pref !== 'system'\)/);
  assert.match(blade, /pref = 'light';/);
});

test('VoiceDictationButton does not request microphone permission on render and uses neutral network fallback wording', () => {
  let speechRecognitionConstructed = false;
  globalThis.window = {
    SpeechRecognition: class {
      constructor() {
        speechRecognitionConstructed = true;
      }
    },
  };

  try {
    const html = renderToStaticMarkup(
      React.createElement(voiceModule.VoiceDictationButton, {
        onTranscript: () => {},
      })
    );
    assert.equal(speechRecognitionConstructed, false, 'SpeechRecognition must only be instantiated on user click');
    assert.match(html, /aria-label="Start voice dictation"/);
    assert.match(html, /aria-pressed="false"/);
    assert.match(html, />Voice</);
    assert.match(
      voiceSource,
      /Speech recognition service is currently unavailable\. Click Voice to retry or type directly\./
    );
    assert.doesNotMatch(voiceSource, /Brave blocks/i);
  } finally {
    delete globalThis.window;
  }
});

test('leaving voice dictation stops recording without uploading audio', async () => {
  let cleanup;
  let recorder;
  let uploads = 0;
  let stopped = 0;
  const fakeReact = {
    ...React,
    useState: (initial) => [initial, () => {}],
    useRef: (initial) => ({ current: initial }),
    useEffect: (effect) => { cleanup = effect(); },
  };
  const voice = loadTsxModule('../../resources/js/Components/VoiceDictationButton.tsx',
    (id) => id === 'react' ? fakeReact : require(id)).exports;
  const oldWindow = globalThis.window;
  const oldNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
  const oldRecorder = globalThis.MediaRecorder;
  const oldFetch = globalThis.fetch;
  globalThis.window = {};
  Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {
    mediaDevices: { getUserMedia: async () => ({ getTracks: () => [{ stop: () => { stopped++; } }] }) },
  } });
  globalThis.MediaRecorder = class {
    state = 'inactive';
    mimeType = 'audio/webm';
    constructor() { recorder = this; }
    start() { this.state = 'recording'; }
    stop() { this.state = 'inactive'; void this.onstop?.(); }
  };
  globalThis.fetch = async () => { uploads++; throw new Error('unexpected upload'); };

  try {
    const view = voice.VoiceDictationButton({ onTranscript: () => assert.fail('unexpected transcript') });
    view.props.children[0].props.onClick();
    await new Promise(setImmediate);
    recorder.ondataavailable({ data: new Blob(['voice']) });
    cleanup();
    await new Promise(setImmediate);
    assert.equal(uploads, 0);
    assert.ok(stopped > 0);

    // Permission can resolve after the user navigates away. The late stream
    // must be stopped without starting another recorder or sending audio.
    let resolvePermission;
    navigator.mediaDevices.getUserMedia = () => new Promise((resolve) => { resolvePermission = resolve; });
    const pending = voice.VoiceDictationButton({ onTranscript: () => assert.fail('unexpected transcript') });
    const firstRecorder = recorder;
    pending.props.children[0].props.onClick();
    cleanup();
    const before = stopped;
    resolvePermission({ getTracks: () => [{ stop: () => { stopped++; } }] });
    await new Promise(setImmediate);
    assert.equal(stopped, before + 1);
    assert.equal(recorder, firstRecorder);
    assert.equal(uploads, 0);
  } finally {
    if (oldWindow === undefined) delete globalThis.window; else globalThis.window = oldWindow;
    if (oldNavigator) Object.defineProperty(globalThis, 'navigator', oldNavigator); else delete globalThis.navigator;
    if (oldRecorder === undefined) delete globalThis.MediaRecorder; else globalThis.MediaRecorder = oldRecorder;
    globalThis.fetch = oldFetch;
  }
});

test('ThinkingAnimation uses honest status text without fake terminal logs or space/nebula glow orbs', () => {
  const html = renderToStaticMarkup(
    React.createElement(thinkingModule.ThinkingAnimation, {
      isActive: true,
      steps: [{ label: 'Executing browser & DOM assertions', status: 'active' }],
      title: 'Running isolated verifier checks…',
      subtitle: 'Waiting for verifier response…',
    })
  );
  assert.match(html, /role="status"/);
  assert.match(html, /Running isolated verifier checks…/);
  assert.match(html, /Executing browser &amp; DOM assertions/);
  assert.doesNotMatch(html, /codex-evaluator|sandboxed-oracle|120Hz|mesh-glow|animate-shimmer/i);
});

test('TaskCardSection includes + Attach screenshot control with explicit local-only privacy disclosure', () => {
  assert.match(taskCardSource, /Attach screenshot/);
  assert.match(taskCardSource, /\/issues\/tasks\/\$\{task\.id\}\/image/);
  assert.match(taskCardSource, /kept local only, not interpreted by Muse/);
  assert.match(taskCardSource, /Saved locally on this machine · not sent to or interpreted by Muse/);
});

test('a private task does not offer the public-only file preview', () => {
  const task = { id: 1, title: 'Private task', description: 'Saved locally', repository: 'owner/private', status: 'queued', updatedAt: null, hasImage: false };
  const html = renderToStaticMarkup(React.createElement(taskCardModule.FocusedLocalTaskView, {
    task, preview: null, proposalPreview: null, proposalResult: null, isPrivate: true,
  }));
  assert.match(html, /Task-card suggestions for private repositories are not available yet/);
  assert.doesNotMatch(html, /Pin Public File|Preview file/);
});

test('a private dedicated issue offers only the offline reference repair', () => {
  const { exports: proposal } = loadTsxModule('../../resources/js/Components/workbench/ProposalDiffCard.tsx', (id) => {
    if (id === '../ui/ThinkingAnimation') return thinkingModule;
    return require(id);
  });
  const html = renderToStaticMarkup(React.createElement(proposal.ProposalDiffCard, {
    context: { path: 'src/OrderTotal.php' }, preview: { digest: 'a'.repeat(64), body: {}, estimatedMicros: 1000 },
    suggestion: null, sending: false, modelContextAllowed: false, modelKeyAvailable: true,
    isDedicatedTestRepo: true, isOwnedFixture: true, budget: { canRequest: true },
    verificationReport: null, passedGateCount: 0, copiedCandidateSha: false, copyCandidateHash() {}, onRequestSuggestion() {},
  }));
  assert.match(html, /<button[^>]*>.*Use reference repair<\/span><\/button>/);
  assert.doesNotMatch(html, /Ask Muse|Review Muse request/);
  assert.match(html, /Private repository content stays local/);
});

test('private test case requires a separate provider consent before asking Muse', () => {
  const { exports: proposal } = loadTsxModule('../../resources/js/Components/workbench/ProposalDiffCard.tsx', (id) => {
    if (id === '../ui/ThinkingAnimation') return thinkingModule;
    return require(id);
  });
  const html = renderToStaticMarkup(React.createElement(proposal.ProposalDiffCard, {
    context: { path: 'src/OrderTotal.php' }, preview: { destination: 'https://example.test', digest: 'a'.repeat(64), body: {}, estimatedMicros: 1000 },
    suggestion: null, sending: false, modelContextAllowed: true, modelKeyAvailable: true, privateModelConsentRequired: true,
    isDedicatedTestRepo: true, isOwnedFixture: true, budget: { canRequest: true },
    verificationReport: null, passedGateCount: 0, copiedCandidateSha: false, copyCandidateHash() {}, onRequestSuggestion() {},
  }));
  assert.match(html, /I approve sending this private test issue/);
  assert.match(html, /https:\/\/example\.test/);
  assert.match(html, /<button type="submit" disabled=""/);
  assert.match(html, /test harness stays local/);
});

test('the proposal shows fixture results without claiming an unchecked change passed', () => {
  const { exports: proposal } = loadTsxModule('../../resources/js/Components/workbench/ProposalDiffCard.tsx', (id) => {
    if (id === '../ui/ThinkingAnimation') return thinkingModule;
    return require(id);
  });
  const props = {
    context: { path: 'src/OrderTotal.php' }, preview: null,
    suggestion: { path: 'src/OrderTotal.php', find: 'before', replace: 'after', candidate: { verified: false } },
    sending: false, modelContextAllowed: true, modelKeyAvailable: true,
    isDedicatedTestRepo: true, isOwnedFixture: false, budget: { canRequest: true },
    verificationReport: null, passedGateCount: 0, copiedCandidateSha: false, copyCandidateHash() {}, onRequestSuggestion() {},
  };
  const before = renderToStaticMarkup(React.createElement(proposal.ProposalDiffCard, props));
  const after = renderToStaticMarkup(React.createElement(proposal.ProposalDiffCard, { ...props, fixtureChecksPassed: true }));
  assert.match(before, /Not checked yet/);
  assert.doesNotMatch(before, /Fixture checks passed/);
  assert.match(after, /Fixture checks passed/);
  assert.doesNotMatch(after, /Not checked yet/);
});

test('public Muse review is enabled only when approved, funded and configured', () => {
  const { exports: review } = loadTsxModule('../../resources/js/Components/repositories/PrReviewTab.tsx',
    (id) => {
      if (id === '@inertiajs/react') return inertiaMock;
      if (id === '../ui/budgetCopy') return budgetCopy;
      if (id === '../ui/date') return dateFormat;
      return require(id);
    });
  const props = {
    review: null, reviewPreview: { filesCount: 1, estimatedMicros: 1000, digest: 'a'.repeat(64), body: {} },
    changedFiles: [], baseContextPath: null,
    isPrivate: false, reviewApproved: true, reviewProcessing: false, budget: { canRequest: true, attempts: 0, maxAttempts: 10 },
    modelKeyAvailable: true, copiedReviewDigest: false, setReviewApproved() {}, copyText() {}, handleReviewSubmit() {}, setReviewFormData() {},
  };
  const enabled = renderToStaticMarkup(React.createElement(review.PrReviewTab, props));
  assert.match(enabled, /<button type="submit" class=/);
  const noKey = renderToStaticMarkup(React.createElement(review.PrReviewTab, { ...props, modelKeyAvailable: false }));
  assert.match(noKey, /<button type="submit" disabled=""/);
  const privatePull = renderToStaticMarkup(React.createElement(review.PrReviewTab, { ...props, isPrivate: true }));
  assert.match(privatePull, /<button type="submit" disabled=""/);
});

test('split diffs keep saved drafts visible and allow comments on both sides', () => {
  const { exports: inlineDraft } = loadTsxModule('../../resources/js/Components/diff/InlineDraft.tsx');
  const { exports: split } = loadTsxModule('../../resources/js/Components/diff/SplitDiffTable.tsx', (id) => {
    if (id === './InlineDraft') return inlineDraft;
    if (id === './InlineCommentEditor') return { InlineCommentEditor: () => null };
    return require(id);
  });
  const html = renderToStaticMarkup(React.createElement(split.SplitDiffTable, {
    fileName: 'src/Example.php', splitRows: [{ type: 'row', left: { lineNum: 4, type: 'deletion', content: 'old' }, right: { lineNum: 5, type: 'addition', content: 'new' } }],
    fileDrafts: [{ id: 'draft-1', line: 5, side: 'RIGHT', comment: 'Check this case' }],
    activeEditorLineKey: null, openEditorForLine() {}, closeEditor() {}, onSaveDraft() {}, onDeleteDraft() {},
  }));
  assert.match(html, /Add comment on line 4 \(base\)/);
  assert.match(html, /Add comment on line 5/);
  assert.match(html, /Saved Local Draft/);
  assert.match(html, /Check this case/);
});

test('FocusedLocalTaskView renders actual task preview, single-use approval step, and unverified mocked proposal diff', () => {
  const task = {
    id: 7,
    title: 'Fix zero-quantity order total calculation',
    description: 'Ensure zero-quantity line items contribute 0 instead of unit_price * 1.',
    repository: 'prateekbhujel/proofPatch-test-sandbox',
    status: 'queued',
    updatedAt: '2026-10-30T12:00:00Z',
    hasImage: false,
  };

  const preview = {
    status: 'local_preview_not_sent',
    digest: 'a'.repeat(64),
    context: {
      cardId: 7,
      cardVersion: 'b'.repeat(64),
      repository: 'prateekbhujel/proofPatch-test-sandbox',
      title: task.title,
      description: task.description,
      path: 'src/OrderTotal.php',
      commit: '676e73d674aca9ba711aa10877ffd7287e1f25b3',
      source: "<?php\n$total += $item['unit_price'] * max(1, $item['quantity']);\n",
      sourceSha256: 'c'.repeat(64),
    },
  };

  // Stage 1: Preview ready, waiting for public-text confirmation & model-preview preparation
  const stage1Html = renderToStaticMarkup(
    React.createElement(taskCardModule.FocusedLocalTaskView, {
      task,
      preview,
      proposalPreview: null,
      proposalResult: null,
      modelKeyAvailable: true,
      budgetCanRequest: true,
    })
  );
  assert.match(stage1Html, /Local preview ready · not sent to any model/);
  assert.match(stage1Html, /src\/OrderTotal\.php @ 676e73d6/);
  assert.match(stage1Html, /2\. Prepare single-file model request/);
  assert.match(stage1Html, /Review exact request/);

  // Stage 2: Single-use approval prepared (proposalPreview present)
  const proposalPreview = {
    cardId: 7,
    cardVersion: 'b'.repeat(64),
    previewDigest: 'a'.repeat(64),
    destination: 'https://inference.baseten.co/v1/chat/completions',
    body: { model: 'meta-models/muse-spark-open-1026', messages: [] },
    digest: 'd'.repeat(64),
    estimatedMicros: 15900,
  };

  const stage2Html = renderToStaticMarkup(
    React.createElement(taskCardModule.FocusedLocalTaskView, {
      task,
      preview,
      proposalPreview,
      proposalResult: null,
      modelKeyAvailable: true,
      budgetCanRequest: true,
    })
  );
  assert.match(stage2Html, /2\. Muse Spark Open wants to read this public task &amp; file/);
  assert.match(stage2Html, /POST https:\/\/inference\.baseten\.co\/v1\/chat\/completions · src\/OrderTotal\.php/);
  assert.match(stage2Html, />Allow once</);
  assert.match(stage2Html, /Single-use approval · Digest pinned/);

  // Stage 3: Mocked proposal received -> renders inline diff, hides approval prompt, marks unverified in-memory preview
  const proposalResult = {
    status: 'unverified_suggestion',
    cardId: 7,
    cardVersion: 'b'.repeat(64),
    previewDigest: 'a'.repeat(64),
    repository: task.repository,
    commit: preview.context.commit,
    path: 'src/OrderTotal.php',
    sourceSha256: 'c'.repeat(64),
    find: "max(1, $item['quantity'])",
    replace: "$item['quantity']",
    explanation: 'Multiply unit price directly by item quantity so zero-quantity items add zero.',
    model: 'meta-models/muse-spark-open-1026',
    requestDigest: 'd'.repeat(64),
    usage: { prompt_tokens: 180, completion_tokens: 45 },
    candidate: {
      status: 'unverified_in_memory_preview',
      source: "<?php\n$total += $item['unit_price'] * $item['quantity'];\n",
      sha256: 'e'.repeat(64),
      applied: false,
      verified: false,
    },
  };

  const stage3Html = renderToStaticMarkup(
    React.createElement(taskCardModule.FocusedLocalTaskView, {
      task,
      preview,
      proposalPreview: null,
      proposalResult,
      modelKeyAvailable: true,
      budgetCanRequest: true,
    })
  );
  assert.match(stage3Html, /Unverified In-Memory Preview/);
  assert.match(stage3Html, /max\(1, \$item\[&#x27;quantity&#x27;\]\)/);
  assert.match(stage3Html, /\$item\[&#x27;quantity&#x27;\]/);
  assert.match(stage3Html, /Allowed once · meta-models\/muse-spark-open-1026 \(225 tokens\)/);
  assert.match(stage3Html, /not written to disk/);
  assert.doesNotMatch(stage3Html, />Allow once</);
});

test('the task composer offers a searchable repo picker, formatting toolbar, and image attach', () => {
  const html = renderToStaticMarkup(React.createElement(taskCardModule.NewTaskComposer, {
    githubConnection: {
      isConnected: true,
      repositoryCount: 2,
      selectedRepo: 'owner/one',
    },
  }));
  assert.match(html, /role="combobox"/);
  assert.match(html, /Search repositories/);
  assert.match(html, /aria-label="Formatting"/);
  for (const tool of ['Bold', 'Italic', 'Code', 'Quote', 'Bulleted list', 'Link']) {
    assert.match(html, new RegExp(`aria-label="${tool}"`));
  }
  assert.match(html, /Attach a screenshot/);
  assert.match(html, /accept="image\/png,image\/jpeg"/);
});

test('the repo search picker fetches nothing until opened', () => {
  // Closed by default: no listbox, no fetch during render (effects only).
  const html = renderToStaticMarkup(React.createElement(repoPickerModule.RepoSearchPicker, {
    id: 'repo-pick',
    value: 'owner/one',
    onChange: () => {},
  }));
  assert.match(html, /role="combobox"/);
  assert.match(html, /aria-expanded="false"/);
  assert.match(html, /Search repositories/);
  assert.doesNotMatch(html, /role="listbox"/);
});


