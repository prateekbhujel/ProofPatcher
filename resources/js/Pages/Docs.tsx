import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';
import { copyText } from '../Components/ui/copyText';
import {
  Shield,
  FileCode,
  CheckCircle2,
  Lock,
  Sparkles,
  ArrowRight,
  Cpu,
  GitPullRequest,
  Check,
  AlertTriangle,
  BookOpen,
  Terminal,
  Copy,
} from 'lucide-react';

export default function Docs() {
  const [activeTab, setActiveTab] = useState<'workflow' | 'verification' | 'receipts' | 'security'>('workflow');
  const [copiedCmd, setCopiedCmd] = useState(false);

  const copyCommand = (text: string) => {
    void copyText(text).then((ok) => {
      if (!ok) return;
      setCopiedCmd(true);
      setTimeout(() => setCopiedCmd(false), 2000);
    });
  };

  return (
    <AppLayout activeNav="docs">
      <Head title="Documentation · ProofPatcher" />

      <div className="space-y-6 w-full max-w-4xl mx-auto">
        {/* Docs Header */}
        <div className="border-b border-slate-200 dark:border-slate-800 pb-4">
          <div className="flex items-center gap-2">
            <span className="p-1 rounded bg-accent-100 dark:bg-accent-950 text-accent-700 dark:text-accent-300">
              <BookOpen className="w-4 h-4" />
            </span>
            <h1 className="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Documentation</h1>
          </div>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            A guide to reviews, supported fixture checks, and evidence receipts.
          </p>
        </div>

        {/* Tab Navigation */}
        <div className="border-b border-slate-200 dark:border-slate-800">
          <nav className="flex space-x-6 sm:space-x-8 overflow-x-auto">
            <button
              type="button"
              onClick={() => setActiveTab('workflow')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'workflow'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <GitPullRequest className="w-3.5 h-3.5" />
              <span>Workflow &amp; Lifecycle</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('verification')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'verification'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <CheckCircle2 className="w-3.5 h-3.5" />
              <span>5-Gate Verifier</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('receipts')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'receipts'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <Shield className="w-3.5 h-3.5" />
              <span>Cryptographic Receipts</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('security')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'security'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <Lock className="w-3.5 h-3.5" />
              <span>Security &amp; Sandboxes</span>
            </button>
          </nav>
        </div>

        {/* ═══════════════════════ TAB 1: WORKFLOW & LIFECYCLE ═══════════════════════ */}
        {activeTab === 'workflow' && (
          <div className="space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">How a change is reviewed</h2>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                In the dedicated test case, a candidate is checked before a draft pull request can be created. Reviews are advisory.
              </p>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 pt-2">
                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1.5">
                  <div className="flex items-center gap-2 font-semibold text-xs text-slate-900 dark:text-slate-100">
                    <span className="w-5 h-5 rounded-full bg-accent-100 dark:bg-accent-900/60 text-accent-700 dark:text-accent-300 flex items-center justify-center text-[11px] font-bold">1</span>
                    <span>Ticket Intake</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Select a connected repository issue or pull request. Supported workflows record a file hash and check freshness before relevant actions.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1.5">
                  <div className="flex items-center gap-2 font-semibold text-xs text-slate-900 dark:text-slate-100">
                    <span className="w-5 h-5 rounded-full bg-accent-100 dark:bg-accent-900/60 text-accent-700 dark:text-accent-300 flex items-center justify-center text-[11px] font-bold">2</span>
                    <span>Single-Use Maintainer Approval</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Manual model requests require approval. Optional public automatic reviews follow the settings for each repository.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1.5">
                  <div className="flex items-center gap-2 font-semibold text-xs text-slate-900 dark:text-slate-100">
                    <span className="w-5 h-5 rounded-full bg-accent-100 dark:bg-accent-900/60 text-accent-700 dark:text-accent-300 flex items-center justify-center text-[11px] font-bold">3</span>
                    <span>In-Memory Diff Preview</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    The model returns a minimal replacement pair. ProofPatcher generates an in-memory diff preview without modifying files on disk.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1.5">
                  <div className="flex items-center gap-2 font-semibold text-xs text-slate-900 dark:text-slate-100">
                    <span className="w-5 h-5 rounded-full bg-accent-100 dark:bg-accent-900/60 text-accent-700 dark:text-accent-300 flex items-center justify-center text-[11px] font-bold">4</span>
                    <span>5-Gate Execution &amp; Receipt</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    The dedicated fixture checks a baseline, negative control and candidate, then records an evidence receipt.
                  </p>
                </div>
              </div>

              <div className="pt-3 flex items-center gap-3">
                <Link
                  href="/issues"
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 hover:bg-slate-800 dark:hover:bg-white transition"
                >
                  <span>Open Issues Workbench</span>
                  <ArrowRight className="w-3 h-3" />
                </Link>
                <Link
                  href="/repositories"
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                >
                  <span>Review Pull Requests</span>
                  <ArrowRight className="w-3 h-3" />
                </Link>
              </div>
            </div>
          </div>
        )}

        {/* ═══════════════════════ TAB 2: 5-GATE VERIFIER ═══════════════════════ */}
        {activeTab === 'verification' && (
          <div className="space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <div>
                <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">The 5-Gate Proof Structure</h2>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                  The dedicated issue fixture uses five checks; the checkout example uses four. Passing checks is not proof of general correctness.
                </p>
              </div>

              <div className="space-y-3 pt-1">
                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-xs text-slate-900 dark:text-slate-100">Gate 1: Static PHP token checks</span>
                    <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200">Pre-Execution</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Checks PHP tokens with <code className="font-mono text-accent-700 dark:text-accent-400">token_get_all(..., TOKEN_PARSE)</code> and rejects selected unsafe constructs. These checks are not a security sandbox.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-xs text-slate-900 dark:text-slate-100">Gate 2: Baseline Failure Reproduction</span>
                    <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-400">Exit Code 1</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Runs the fixture checks against the unpatched base and looks for the expected failure.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-xs text-slate-900 dark:text-slate-100">Gate 3: Negative Control Guard</span>
                    <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-400">Exit Code 1</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Tests a known incorrect candidate to check that the fixture rejects it.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-xs text-slate-900 dark:text-slate-100">Gate 4: Candidate Test Execution</span>
                    <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400">Exit Code 0</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Runs the supported pinned candidate against the fixture checks. The result applies only to the tested cases.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-xs text-slate-900 dark:text-slate-100">Gate 5: Allowlist &amp; Hash Integrity</span>
                    <span className="text-[10px] font-mono font-semibold px-2 py-0.5 rounded bg-accent-50 text-accent-700 border border-accent-200 dark:bg-accent-950/40 dark:border-accent-800 dark:text-accent-300">SHA-256 Match</span>
                  </div>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Verifies that authoritative test suites and protected repository files were not altered during the candidate execution run.
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ═══════════════════════ TAB 3: CRYPTOGRAPHIC RECEIPTS ═══════════════════════ */}
        {activeTab === 'receipts' && (
          <div className="space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <div>
                <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Evidence Receipts (proofpatch-receipt-v1)</h2>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    A receipt records hashes for an evidence bundle. A matching digest checks integrity, not correctness or independent origin.
                </p>
              </div>

              <div className="bg-slate-900 text-slate-100 rounded-lg p-4 font-mono text-xs space-y-2 overflow-x-auto border border-slate-800">
                <div className="flex items-center justify-between text-slate-400 text-[11px] border-b border-slate-800 pb-2">
                  <span>Illustrative receipt</span>
                  <span className="text-emerald-400">Canonical JSON</span>
                </div>
                <pre className="text-emerald-400 leading-relaxed">{`{
  "format": "proofpatch-receipt-v1",
  "kind": "owned_backend",
  "runId": "20261030T131306Z-65eee2f5",
  "baseCommit": "ae624f4e72ba7...",
  "decision": "VERIFIED",
  "files": {
    "result.json": "8a7c29...",
    "patch.diff": "abcc8d...",
    "baseline/run.log": "68600d...",
    "negative/run.log": "1c3a17...",
    "candidate/run.log": "68538a..."
  },
  "receiptSha256": "1166bf6f22ff3a8b..."
}`}</pre>
              </div>

              <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/60 flex items-center justify-between gap-3">
                <div className="space-y-0.5">
                  <span className="text-xs font-semibold text-slate-900 dark:text-slate-100">Verify Receipt via CLI</span>
                  <p className="text-[11px] text-slate-500 font-mono dark:text-slate-400">php artisan review:receipt &lt;runId&gt;</p>
                </div>
                <button
                  type="button"
                  onClick={() => copyCommand('php artisan review:receipt ')}
                  className="px-2.5 py-1 text-xs font-medium rounded-md border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 inline-flex items-center gap-1.5"
                >
                  {copiedCmd ? <Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" /> : <Copy className="w-3 h-3" />}
                  <span>{copiedCmd ? 'Copied' : 'Copy'}</span>
                </button>
              </div>
            </div>
          </div>
        )}

        {/* ═══════════════════════ TAB 4: SECURITY & SANDBOXES ═══════════════════════ */}
        {activeTab === 'security' && (
          <div className="space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <div className="p-3.5 rounded-lg border border-amber-200 dark:border-amber-900/60 bg-amber-50/70 dark:bg-amber-950/30 text-amber-900 dark:text-amber-200 space-y-1">
                <div className="flex items-center gap-2 font-semibold text-xs">
                  <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 dark:text-amber-400" />
                  <span>Worktrees Are NOT Security Sandboxes</span>
                </div>
                <p className="text-[11px] text-amber-800 dark:text-amber-300 leading-relaxed">
                  Git worktrees separate checkouts; they do not isolate host syscalls or network access. Do not run untrusted code on the host merely because it is in a worktree.
                </p>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 pt-2">
                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1.5">
                  <span className="font-semibold text-xs text-slate-900 dark:text-slate-100 block">Host Allowlist Boundary</span>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Local fixture execution uses allowlists and token checks. These checks do not establish a sandbox for arbitrary code.
                  </p>
                </div>

                <div className="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 space-y-1.5">
                  <span className="font-semibold text-xs text-slate-900 dark:text-slate-100 block">Configured Modal runner</span>
                  <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Modal is used for the configured pinned test case. General generated-code verification remains disabled until the test oracle is isolated.
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
