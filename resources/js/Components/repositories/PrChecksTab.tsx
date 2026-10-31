import React from 'react';
import { Search } from 'lucide-react';
import { CheckRun } from './types';

interface PrChecksTabProps {
  checks: CheckRun[];
  filteredChecks: CheckRun[];
  checkQuery: string;
  setCheckQuery: (query: string) => void;
  headSha?: string;
}

export function PrChecksTab({
  checks,
  filteredChecks,
  checkQuery,
  setCheckQuery,
  headSha,
}: PrChecksTabProps) {
  return (
    <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-6 space-y-4 dark:bg-[#141821] dark:border-slate-800/80">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">GitHub Check Runs</h2>
          <p className="text-xs text-slate-500 dark:text-slate-400">External CI and automated status checks</p>
        </div>
        <div className="relative">
          <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            placeholder="Filter checks..."
            value={checkQuery}
            onChange={(e) => setCheckQuery(e.target.value)}
            className="pl-8 pr-3 py-1.5 text-xs rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-accent-500 shadow-2xs dark:border-slate-700"
          />
        </div>
      </div>

      <div className="divide-y divide-slate-100 border border-slate-200 rounded-lg overflow-hidden dark:border-slate-800 dark:divide-slate-800">
        {filteredChecks.length === 0 ? (
          <div className="p-4 text-center text-xs text-slate-500 dark:text-slate-400">No check runs recorded.</div>
        ) : (
          filteredChecks.map((c, i) => {
            const outcome = (c.conclusion || c.status).toLowerCase();
            const isSuccess = outcome === 'success';
            return (
              <div key={i} className="p-3.5 flex items-center justify-between gap-4 hover:bg-slate-50/50 dark:hover:bg-slate-800/50">
                <div className="flex items-center gap-2.5">
                  <span
                    className={`w-2 h-2 rounded-full ${
                      isSuccess ? 'bg-emerald-500' : 'bg-amber-500'
                    }`}
                  />
                  <span className="text-xs font-medium text-slate-900 dark:text-slate-100">{c.name}</span>
                </div>
                <div className="flex items-center gap-2">
                  {!isSuccess && (
                    <button
                      type="button"
                      onClick={() => {
                        const sha = headSha ? headSha.slice(0, 8) : 'latest';
                        navigator.clipboard?.writeText(
                          `CI Check Notice (${c.name}): Status is "${c.conclusion || c.status}" on commit ${sha}. Please inspect failing check logs before merge.`
                        );
                      }}
                      className="text-[11px] font-medium px-2 py-0.5 rounded border border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100 transition dark:bg-amber-950/40 dark:text-amber-200"
                    >
                      Copy CI Diagnostic Note
                    </button>
                  )}
                  <span
                    className={`text-xs px-2 py-0.5 rounded font-mono font-medium ${
                      isSuccess
                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                        : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'
                    }`}
                  >
                    {c.conclusion || c.status}
                  </span>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
