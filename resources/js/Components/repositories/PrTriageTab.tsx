import React from 'react';
import { Shield, Check, Copy } from 'lucide-react';
import { TriageData } from './types';

interface PrTriageTabProps {
  triage: TriageData | null;
  copiedFeedback: boolean;
  setCopiedFeedback: (copied: boolean) => void;
  onVerify5Gates: () => void;
}

export function PrTriageTab({
  triage,
  copiedFeedback,
  setCopiedFeedback,
  onVerify5Gates,
}: PrTriageTabProps) {
  if (!triage) {
    return <p className="text-xs text-slate-500 dark:text-slate-400">Triage data is unavailable for this pull request.</p>;
  }

  return (
    <div className="space-y-6">
      {/* 1. Triage Banner */}
      <div
        className={`p-6 rounded-2xl border shadow-xs space-y-4 ${
          triage.summary.classification === 'POLICY_VIOLATION' || triage.summary.classification === 'CHANGES_REQUESTED'
            ? 'bg-rose-50/70 border-rose-200 dark:bg-rose-950/40/70 dark:border-rose-800'
            : triage.summary.classification === 'CLEAN_PR'
            ? 'bg-emerald-50/70 border-emerald-200 dark:bg-emerald-950/40/70 dark:border-emerald-800'
            : 'bg-amber-50/70 border-amber-200 dark:bg-amber-950/40/70 dark:border-amber-800'
        }`}
      >
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-start gap-3">
            <span
              className={`w-3 h-3 rounded-full mt-1.5 shrink-0 ${
                triage.summary.classification === 'POLICY_VIOLATION' || triage.summary.classification === 'CHANGES_REQUESTED'
                  ? 'bg-rose-600'
                  : triage.summary.classification === 'CLEAN_PR'
                  ? 'bg-emerald-600'
                  : 'bg-amber-600'
              }`}
            />
            <div>
              <div className="flex items-center gap-2">
                <h3
                  className={`text-base font-bold ${
                    triage.summary.classification === 'POLICY_VIOLATION' || triage.summary.classification === 'CHANGES_REQUESTED'
                      ? 'text-rose-900 dark:text-rose-200'
                      : triage.summary.classification === 'CLEAN_PR'
                      ? 'text-emerald-900 dark:text-emerald-200'
                      : 'text-amber-900 dark:text-amber-200'
                  }`}
                >
                  {triage.summary.headline}
                </h3>
                <span
                  className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold ${
                    triage.summary.classification === 'POLICY_VIOLATION' || triage.summary.classification === 'CHANGES_REQUESTED'
                      ? 'bg-rose-200/80 text-rose-800 dark:text-rose-300'
                      : triage.summary.classification === 'CLEAN_PR'
                      ? 'bg-emerald-200/80 text-emerald-800 dark:text-emerald-300'
                      : 'bg-amber-200/80 text-amber-800 dark:text-amber-300'
                  }`}
                >
                  {triage.summary.badge || triage.summary.verdict}
                </span>
              </div>
              <p className="text-xs text-slate-600 mt-0.5 dark:text-slate-400">{triage.summary.detail}</p>
            </div>
          </div>

          <div className="flex items-center gap-2 self-start sm:self-center">
            {triage.summary.classification === 'CLEAN_PR' ? (
              <button
                type="button"
                onClick={onVerify5Gates}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 shadow-xs transition-colors"
              >
                <Shield className="w-3.5 h-3.5" />
                <span>Verify 5 Gates</span>
              </button>
            ) : (
              <button
                type="button"
                onClick={() => {
                  if (triage.summary.suggestedComment) {
                    navigator.clipboard?.writeText(triage.summary.suggestedComment);
                    setCopiedFeedback(true);
                    setTimeout(() => setCopiedFeedback(false), 2000);
                  }
                }}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold shadow-xs transition-colors bg-slate-900 text-white hover:bg-slate-800"
              >
                {copiedFeedback ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                <span>{copiedFeedback ? 'Copied Response!' : 'Copy Review Response'}</span>
              </button>
            )}
          </div>
        </div>

        {/* Reasoning & Flags */}
        {triage.summary.reasoning && (
          <div className="p-3 rounded-lg bg-white/80 border border-slate-200/80 text-xs text-slate-700 space-y-2 dark:bg-[#141821]/80 dark:border-slate-800/80 dark:text-slate-300">
            <div className="font-medium text-slate-900 dark:text-slate-100">Triage Analysis:</div>
            <p className="text-slate-600 dark:text-slate-400">{triage.summary.reasoning}</p>

            {triage.summary.flags && triage.summary.flags.length > 0 && (
              <div className="flex flex-wrap gap-1.5 pt-1">
                {triage.summary.flags.map((flag) => (
                  <span
                    key={flag}
                    className="px-2 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:border-slate-800 dark:text-slate-300"
                  >
                    {flag.replace(/_/g, ' ')}
                  </span>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Suggested Response Preview */}
        {triage.summary.suggestedComment && (
          <div className="space-y-1.5 pt-1">
            <div className="flex items-center justify-between text-xs">
              <span className="font-semibold text-slate-800 dark:text-slate-200">
                Actionable Review Feedback for Contributor:
              </span>
              <span className="text-[11px] text-slate-500 dark:text-slate-400">Ready to post or use as maintainer feedback</span>
            </div>
            <pre className="p-3 rounded-lg bg-white border border-slate-200 text-xs font-mono text-slate-800 whitespace-pre-wrap leading-relaxed dark:bg-[#141821] dark:border-slate-800 dark:text-slate-200">
              {triage.summary.suggestedComment}
            </pre>
          </div>
        )}
      </div>

      {/* Policy Gate Summary */}
      <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-6 space-y-4 dark:bg-[#141821] dark:border-slate-800/80">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
          <div>
            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">Maintainer Policy Evaluation</h2>
            <p className="text-xs text-slate-500 dark:text-slate-400">Configured via .proofpatchrc.json in repository root</p>
          </div>
          <span
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold ${
              triage.summary.classification === 'CLEAN_PR'
                ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400'
                : triage.summary.classification === 'POLICY_VIOLATION' || triage.summary.classification === 'CHANGES_REQUESTED'
                ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-400'
                : 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-400'
            }`}
          >
            <span>{triage.summary.suggestedAction || 'Human review needed'}</span>
          </span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs">
          <div className="p-3 rounded-lg bg-slate-50 border border-slate-200/80 space-y-1 dark:bg-slate-900 dark:border-slate-800/80">
            <div className="text-slate-500 dark:text-slate-400">Max Diff Lines</div>
            <div className="text-sm font-bold font-mono text-slate-900 dark:text-slate-100">
              {triage.target.filesTruncated ? 'At least ' : ''}{triage.policy.totalChanges} / {triage.policy.maxDiffLines} lines
            </div>
            <div className={`font-medium ${triage.policy.diffExceeded ? 'text-rose-600' : 'text-emerald-600'} dark:text-emerald-400`}>
              {triage.policy.diffExceeded ? 'Exceeded limit' : triage.target.filesTruncated ? 'Incomplete file list' : 'Within limit'}
            </div>
          </div>

          <div className="p-3 rounded-lg bg-slate-50 border border-slate-200/80 space-y-1 dark:bg-slate-900 dark:border-slate-800/80">
            <div className="text-slate-500 dark:text-slate-400">Test Coverage</div>
            <div className={`text-sm font-bold ${triage.target.testPaths.length > 0 ? 'text-emerald-600' : 'text-amber-600'} dark:text-amber-400`}>
              {triage.target.testPaths.length > 0 ? `${triage.target.testPaths.length} Test File(s)` : '0 Test Files'}
            </div>
            <div className="text-slate-500 truncate dark:text-slate-400">
              {triage.target.testPaths.length > 0 ? triage.target.testPaths.join(', ') : 'No tests found in diff'}
            </div>
          </div>

          <div className="p-3 rounded-lg bg-slate-50 border border-slate-200/80 space-y-1 dark:bg-slate-900 dark:border-slate-800/80">
            <div className="text-slate-500 dark:text-slate-400">Target Base Branch</div>
            <div className={`text-sm font-bold font-mono ${triage.target.base && triage.target.base !== 'main' ? 'text-amber-600' : 'text-slate-900'} dark:text-slate-100`}>
              {triage.target.base || 'main'}
            </div>
            <div className="text-slate-500 dark:text-slate-400">
              {triage.target.base && triage.target.base !== 'main' ? 'Dev/custom branch' : 'Default main branch'}
            </div>
          </div>

          <div className="p-3 rounded-lg bg-slate-50 border border-slate-200/80 space-y-1 dark:bg-slate-900 dark:border-slate-800/80">
            <div className="text-slate-500 dark:text-slate-400">Enforcement Mode</div>
            <div className="text-sm font-bold text-slate-900 dark:text-slate-100">{triage.policy.mode}</div>
            <div className="text-slate-500 truncate dark:text-slate-400">{triage.summary.badge || 'Advisory check'}</div>
          </div>
        </div>
      </div>

      {/* PR Queue Overlap Comparisons */}
      <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-6 space-y-4 dark:bg-[#141821] dark:border-slate-800/80">
        <div>
          <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">Captured PR comparisons</h2>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Shared file paths from saved snapshots in this repository. This is not a complete PR queue or a duplicate check.
          </p>
        </div>

        {triage.comparisons.length === 0 ? (
          <div className="p-6 rounded-lg bg-slate-50 border border-slate-200 text-center space-y-2 dark:bg-slate-900 dark:border-slate-800">
            <div className="text-sm font-semibold text-slate-900 dark:text-slate-100">No other captured PRs to compare</div>
            <p className="text-xs text-slate-500 max-w-md mx-auto dark:text-slate-400">
              Capture another PR from this repository to compare file paths.
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {triage.comparisons.map((comp, idx) => (
              <div
                key={idx}
                className={`p-4 rounded-xl border space-y-3 ${
                  comp.matchPercentage >= 75
                    ? 'bg-amber-50/60 border-amber-200 dark:bg-amber-950/40/60 dark:border-amber-800'
                    : 'bg-slate-50/80 border-slate-200 dark:bg-slate-900/80 dark:border-slate-800'
                }`}
              >
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div className="space-y-0.5">
                    <div className="flex items-center gap-2">
                      <span className="font-bold text-slate-900 text-sm dark:text-slate-100">
                        #{comp.candidate.number}
                      </span>
                      <span className="text-slate-400">·</span>
                      <span className="text-xs text-slate-700 font-medium dark:text-slate-300">
                        {comp.candidate.title}
                      </span>
                    </div>
                    <div className="text-xs text-slate-500 font-mono dark:text-slate-400">
                      Head commit {comp.candidate.headSha.slice(0, 8)} · {comp.candidate.fileCount} files
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <span
                      className={`px-2.5 py-1 rounded-md text-xs font-bold font-mono ${
                        comp.matchPercentage >= 75
                          ? 'bg-amber-100 text-amber-900 border border-amber-300 dark:text-amber-200'
                          : 'bg-slate-100 text-slate-800 border border-slate-200 dark:bg-slate-800 dark:border-slate-800 dark:text-slate-200'
                      }`}
                    >
                      {comp.matchPercentage}% file overlap{comp.incomplete ? ' (partial)' : ''}
                    </span>
                    <span className="px-2 py-0.5 rounded text-2xs font-bold uppercase tracking-wider bg-white border border-slate-200 dark:bg-[#141821] dark:border-slate-800">
                      {comp.relationship}
                    </span>
                  </div>
                </div>

                <div className="text-xs bg-white p-2.5 rounded-lg border border-slate-200/80 space-y-1 dark:bg-[#141821] dark:border-slate-800/80">
                  <div className="font-semibold text-slate-800 dark:text-slate-200">
                    {comp.verdictBanner}
                  </div>
                  {comp.sharedFiles.length > 0 && (
                    <div className="text-slate-500 flex flex-wrap items-center gap-1.5 pt-1 dark:text-slate-400">
                      <span>Shared files:</span>
                      {comp.sharedFiles.map((sf, sIdx) => (
                        <code key={sIdx} className="bg-slate-100 text-slate-800 px-1.5 py-0.5 rounded text-2xs dark:bg-slate-800 dark:text-slate-200">
                          {sf}
                        </code>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
