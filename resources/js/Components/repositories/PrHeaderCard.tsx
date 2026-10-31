import React, { useState } from 'react';
import { ExternalLink, Check, Lock, GitMerge, XCircle } from 'lucide-react';
import { router } from '@inertiajs/react';
import { PullData, TriageData } from './types';

interface PrHeaderCardProps {
  pull: PullData;
  triage?: TriageData | null;
  onViewTriage: () => void;
}

export function PrHeaderCard({ pull, triage, onViewTriage }: PrHeaderCardProps) {
  const [pending, setPending] = useState<'merge' | 'close' | null>(null);
  const [confirming, setConfirming] = useState<'merge' | 'close' | null>(null);

  const files = pull.files || [];
  const totalAdditions = files.reduce((acc, f) => acc + (f.additions || 0), 0);
  const totalDeletions = files.reduce((acc, f) => acc + (f.deletions || 0), 0);
  const total = totalAdditions + totalDeletions;
  const addBlocks = total > 0 ? Math.round((totalAdditions / total) * 5) : 0;
  const isOpen = pull.state.toLowerCase() === 'open';

  const submit = (action: 'merge' | 'close') => {
    setPending(action);
    router.post(`/repositories/pull/${action}`, { head_sha: pull.headSha, confirmed: true }, {
      preserveScroll: true,
      onFinish: () => { setPending(null); setConfirming(null); },
    });
  };

  return (
    <div className="bg-white rounded-lg border border-slate-200 shadow-xs p-5 space-y-4">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="space-y-1.5">
          <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 font-medium">
            <span>{pull.repository}</span>
            <span>/</span>
            <span className="font-bold text-slate-900">#{pull.number}</span>
            <span
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold ${
                pull.state.toLowerCase() === 'open'
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                  : pull.state.toLowerCase() === 'merged'
                  ? 'bg-accent-50 text-accent-800 border border-accent-200'
                  : 'bg-slate-100 text-slate-700 border border-slate-200'
              }`}
            >
              {pull.state.toLowerCase() === 'merged' && <Check className="w-3 h-3 text-accent-700" />}
              <span>{pull.state.toUpperCase()}</span>
            </span>
            {pull.state.toLowerCase() === 'merged' && (
              <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200">
                Historical Inspection
              </span>
            )}
            {pull.isPrivate && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-800 border border-amber-200">
                <Lock className="w-2.5 h-2.5 text-amber-700" />
                Private
              </span>
            )}
          </div>

          <h1 className="text-xl font-bold tracking-tight text-slate-900">{pull.title}</h1>

          <div className="flex flex-wrap items-center gap-2 pt-0.5 text-xs">
            <span className="inline-flex items-center gap-1 font-mono bg-slate-100 text-slate-700 px-2 py-0.5 rounded border border-slate-200 text-[11px]">
              <span>{pull.head}</span>
            </span>
            <span className="text-slate-400 font-mono">→</span>
            <span className="inline-flex items-center gap-1 font-mono bg-slate-100 text-slate-700 px-2 py-0.5 rounded border border-slate-200 text-[11px]">
              <span>{pull.base}</span>
            </span>
            <span className="text-slate-300">|</span>
            <span className="font-mono text-slate-500 text-xs">
              Commit{' '}
              <code className="bg-slate-100 text-slate-800 px-1.5 py-0.5 rounded font-mono text-[11px] border border-slate-200">
                {pull.headSha.slice(0, 8)}
              </code>
            </span>
            {files.length > 0 && (
              <>
                <span className="text-slate-300">|</span>
                <div className="inline-flex items-center gap-1.5 font-mono text-xs font-semibold">
                  <span className="text-[#1a7f37]">+{totalAdditions}</span>
                  <span className="text-[#cf222e]">-{totalDeletions}</span>
                  <div className="flex items-center gap-0.5">
                    {Array.from({ length: 5 }).map((_, i) => (
                      <span
                        key={i}
                        className={`w-1.5 h-2 rounded-2xs ${
                          total === 0
                            ? 'bg-slate-200'
                            : i < addBlocks
                            ? 'bg-[#1a7f37]'
                            : 'bg-[#cf222e]'
                        }`}
                      />
                    ))}
                  </div>
                </div>
              </>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 self-start md:self-center">
          {isOpen && confirming === null && (
            <>
              <button
                type="button"
                disabled={pull.isDraft === true}
                onClick={() => setConfirming('merge')}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md text-white bg-emerald-700 hover:bg-emerald-800 disabled:opacity-40 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
              >
                <GitMerge className="w-3.5 h-3.5" aria-hidden="true" />
                <span>{pull.isDraft ? 'Draft · cannot merge' : 'Merge'}</span>
              </button>
              <button
                type="button"
                onClick={() => setConfirming('close')}
                className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md text-rose-700 bg-white border border-rose-300 hover:bg-rose-50 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
              >
                <XCircle className="w-3.5 h-3.5" aria-hidden="true" />
                <span>Close</span>
              </button>
            </>
          )}
          {isOpen && confirming !== null && (
            <div role="group" aria-label="Confirm action" className="flex flex-wrap items-center gap-2 rounded-md border border-slate-300 bg-slate-50 px-2.5 py-1.5 text-xs text-slate-800">
              <span>
                {confirming === 'merge' ? 'Squash and merge' : 'Close without merging'} commit <code className="font-mono">{pull.headSha.slice(0, 7)}</code>?
              </span>
              <button
                type="button"
                autoFocus
                disabled={pending !== null}
                onClick={() => submit(confirming)}
                className={`rounded px-2.5 py-1 font-semibold text-white disabled:opacity-50 ${confirming === 'merge' ? 'bg-emerald-700 hover:bg-emerald-800' : 'bg-rose-700 hover:bg-rose-800'}`}
              >
                {pending ? 'Working…' : 'Confirm'}
              </button>
              <button type="button" disabled={pending !== null} onClick={() => setConfirming(null)} className="px-1.5 py-1 text-slate-600 hover:text-slate-900">
                Cancel
              </button>
            </div>
          )}

          <a
            href={pull.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 shadow-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600"
          >
            <span>View on GitHub</span>
            <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
          </a>
        </div>
      </div>

      {/* Sanitized Triage Alert Bar */}
      {triage && (
        <div className="mt-3 px-3.5 py-2 rounded-lg bg-slate-50 border border-slate-200 flex flex-wrap items-center justify-between gap-2 text-xs">
          <div className="flex items-center gap-2">
            <span
              className={`w-2 h-2 rounded-full ${
                triage.summary.severity === 'emerald'
                  ? 'bg-emerald-500'
                  : triage.summary.severity === 'amber'
                  ? 'bg-amber-500'
                  : 'bg-rose-500'
              }`}
            />
            <span className="font-semibold text-slate-800">
              {triage.policy.diffExceeded
                ? `Policy check: ${triage.policy.totalChanges} lines changed (${triage.policy.maxDiffLines} max)`
                : triage.summary.headline}
            </span>
          </div>
          <button
            type="button"
            onClick={onViewTriage}
            className="text-xs font-medium px-2.5 py-1 rounded bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 shadow-2xs transition"
          >
            View Triage →
          </button>
        </div>
      )}
    </div>
  );
}
