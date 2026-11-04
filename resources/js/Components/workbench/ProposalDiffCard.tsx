import React, { useEffect, useState } from 'react';
import {
  Shield,
  Check,
  Sparkles,
  FileCode,
  CheckCircle2,
  Copy,
} from 'lucide-react';
import { ThinkingAnimation } from '../ui/ThinkingAnimation';
import {
  IssueContext,
  Preview,
  Suggestion,
  BudgetData,
  VerificationReport,
} from './types';

interface ProposalDiffCardProps {
  context: IssueContext;
  preview: Preview | null;
  suggestion: Suggestion | null;
  sending: boolean;
  modelContextAllowed: boolean;
  privateModelConsentRequired?: boolean;
  modelKeyAvailable: boolean;
  isDedicatedTestRepo: boolean;
  isOwnedFixture: boolean;
  budget: BudgetData | null;
  verificationReport: VerificationReport | null;
  fixtureChecksPassed?: boolean;
  passedGateCount: number;
  copiedCandidateSha: boolean;
  copyCandidateHash: (sha: string) => void;
  onRequestSuggestion: (e: React.FormEvent, useOfflineReference?: boolean, privateConsent?: boolean) => void;
}

export function ProposalDiffCard({
  context,
  preview,
  suggestion,
  sending,
  modelContextAllowed,
  privateModelConsentRequired = false,
  modelKeyAvailable,
  isDedicatedTestRepo,
  isOwnedFixture,
  budget,
  verificationReport,
  fixtureChecksPassed = false,
  passedGateCount,
  copiedCandidateSha,
  copyCandidateHash,
  onRequestSuggestion,
}: ProposalDiffCardProps) {
  const [privateConsent, setPrivateConsent] = useState(false);
  useEffect(() => setPrivateConsent(false), [preview?.digest]);
  const canUseReference = isOwnedFixture || isDedicatedTestRepo;
  const canAskMuse = modelContextAllowed && modelKeyAvailable && Boolean(budget?.canRequest);
  const museThinkingSteps = [
    { label: 'Waiting for the proposal', status: sending ? ('active' as const) : ('waiting' as const) },
  ];

  return (
    <>
      <ThinkingAnimation
        isActive={sending}
        steps={museThinkingSteps}
        title="Requesting single-file repair proposal"
        subtitle="Validating target file constraints…"
      />

      {!suggestion && !sending && preview && (
        <form
          onSubmit={(e) => onRequestSuggestion(e, false, privateConsent)}
          className="rounded-2xl border-2 border-amber-200/90 dark:border-amber-800/80 bg-amber-50/25 dark:bg-amber-950/20 p-5 space-y-3.5 shadow-2xs"
        >
          <div className="flex items-start gap-2.5">
            <Shield className="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
            <div>
              <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                {canUseReference ? 'Try the reference repair' : 'Ask Muse for a suggestion'}
              </h2>
              <p className="text-sm text-slate-600 dark:text-slate-400 mt-1">
                {canUseReference
                  ? 'This known repair runs without sending your repository to a model.'
                  : 'Review the request before sending the selected file to Muse.'}
              </p>
            </div>
          </div>

          {canAskMuse && (
          <details className="text-[11px] text-slate-600 dark:text-slate-400">
            <summary className="cursor-pointer font-medium text-slate-700 dark:text-slate-300">
              Review Muse request · estimated ${(preview.estimatedMicros / 1_000_000).toFixed(4)}
            </summary>
            <p className="mt-2">Destination: {preview.destination}</p>
            <pre className="mt-2 max-h-44 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-white dark:bg-slate-900 p-2.5 font-mono text-[11px] text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-800">
              {JSON.stringify(preview.body, null, 2)}
            </pre>
          </details>
          )}

          {canAskMuse && privateModelConsentRequired && (
            <label className="flex items-start gap-2 text-xs text-slate-700 dark:text-slate-300">
              <input type="checkbox" checked={privateConsent} onChange={(event) => setPrivateConsent(event.target.checked)} />
              <span>I approve sending this private test issue and selected source file to {preview.destination}. The test harness stays local.</span>
            </label>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
            <div className="flex flex-wrap items-center gap-2">
              {canUseReference && (
                <>
                  <button
                    type="button"
                    onClick={(e) => onRequestSuggestion(e, true)}
                    disabled={sending}
                    className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-semibold text-white bg-slate-900 hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 disabled:opacity-40 transition"
                  >
                    <Check className="w-3.5 h-3.5" />
                    <span>Use reference repair</span>
                  </button>
                </>
              )}
              {canAskMuse && (
                <button
                  type="submit"
                  disabled={sending || (privateModelConsentRequired && !privateConsent)}
                  className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-semibold text-white bg-slate-900 hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 disabled:opacity-40 transition"
                >
                  <Sparkles className="w-3.5 h-3.5" />
                  <span>Ask Muse</span>
                </button>
              )}
            </div>
            <span className="text-[11px] text-slate-500 font-mono dark:text-slate-400">
              {!modelContextAllowed ? 'Private repository content stays local' : canAskMuse ? 'Approval applies once' : 'Muse is not configured or the local limit is reached'}
            </span>
          </div>
        </form>
      )}

      {/* Inline Diff Card */}
      {suggestion && (
        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] overflow-hidden shadow-2xs">
          <div className="px-4 py-3 bg-slate-50/80 dark:bg-slate-900/60 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
            <div className="flex items-center gap-2 font-mono text-xs font-semibold text-slate-900 dark:text-slate-100">
              <FileCode className="w-4 h-4 text-accent-700 dark:text-accent-400" />
              <span>{suggestion.path}</span>
            </div>
            <div className="flex items-center gap-2">
              <span
                className={`px-2 py-0.5 rounded text-[10px] font-semibold border ${
                  suggestion.candidate?.verified || fixtureChecksPassed
                    ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'
                    : 'bg-blue-50 dark:bg-blue-950/60 text-blue-800 dark:text-blue-300 border-blue-200 dark:border-blue-800'
                }`}
              >
                {suggestion.candidate?.verified || fixtureChecksPassed
                  ? 'Fixture checks passed'
                  : 'Not checked yet'}
              </span>
            </div>
          </div>

          <div className="font-mono text-xs divide-y divide-slate-100 dark:divide-slate-800">
            <div className="bg-rose-50/75 dark:bg-rose-950/30 text-rose-950 dark:text-rose-200 px-4 py-2.5 flex items-start gap-3 overflow-x-auto">
              <span className="text-rose-600 select-none shrink-0 dark:text-rose-400">-</span>
              <pre className="whitespace-pre-wrap break-words">
                {suggestion.find}
              </pre>
            </div>
            <div className="bg-emerald-50/75 dark:bg-emerald-950/30 text-emerald-950 dark:text-emerald-200 px-4 py-2.5 flex items-start gap-3 overflow-x-auto">
              <span className="text-emerald-700 select-none shrink-0 dark:text-emerald-400">+</span>
              <pre className="whitespace-pre-wrap break-words">
                {suggestion.replace}
              </pre>
            </div>
          </div>

          <div className="px-4 py-3 bg-white dark:bg-[#141821] border-t border-slate-200 dark:border-slate-800 space-y-2">
            <p className="text-sm text-slate-700 dark:text-slate-300 leading-relaxed">{suggestion.explanation}</p>

            <div className="flex flex-wrap items-center justify-between gap-2 pt-1 text-xs">
              <div className="flex items-center gap-2 font-mono text-[11px]">
                <CheckCircle2
                  className={`w-3.5 h-3.5 ${
                    verificationReport?.decision === 'VERIFIED'
                      ? 'text-emerald-600 dark:text-emerald-400'
                      : 'text-slate-400'
                  }`}
                />
                <span
                  className={
                    suggestion.isOfflineExample
                      ? 'text-amber-700 dark:text-amber-400 font-medium'
                      : 'text-slate-600 dark:text-slate-400 font-medium'
                  }
                >
                  {suggestion.isOfflineExample ? 'Offline reference' : suggestion.model ? 'Muse suggestion' : 'Suggestion'}
                </span>
                {verificationReport && (
                  <span className="text-slate-500 dark:text-slate-400">
                    · {passedGateCount} passed, {5 - passedGateCount} failed
                  </span>
                )}
              </div>

            </div>
            {suggestion.candidate && (
              <details className="text-xs text-slate-500 dark:text-slate-400">
                <summary className="cursor-pointer">Change details</summary>
                <div className="mt-2 flex min-w-0 items-center gap-2">
                  <code className="min-w-0 break-all">{suggestion.candidate.sha256}</code>
                  <button
                    type="button"
                    onClick={() => copyCandidateHash(suggestion.candidate!.sha256)}
                    className="shrink-0 rounded p-1 text-slate-500 hover:text-slate-900 dark:hover:text-slate-200 dark:text-slate-400"
                    aria-label="Copy change hash"
                    title="Copy change hash"
                  >
                    {copiedCandidateSha ? <Check className="h-4 w-4 text-emerald-600 dark:text-emerald-400" /> : <Copy className="h-4 w-4" />}
                  </button>
                </div>
              </details>
            )}
          </div>
        </div>
      )}
    </>
  );
}
