import React from 'react';

interface PrOverviewTabProps {
  filesCount: number;
  totalAdditions: number;
  totalDeletions: number;
  checksCount: number;
  passedChecks: number;
}

export function PrOverviewTab({
  filesCount,
  totalAdditions,
  totalDeletions,
  checksCount,
  passedChecks,
}: PrOverviewTabProps) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
      <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-5 space-y-2 dark:bg-[#141821] dark:border-slate-800/80">
        <div className="text-xs font-medium text-slate-500 uppercase tracking-wider dark:text-slate-400">Changed Files</div>
        <div className="text-2xl font-bold text-slate-900 dark:text-slate-100">{filesCount}</div>
        <div className="text-xs text-slate-500 flex items-center gap-2 dark:text-slate-400">
          <span className="text-emerald-600 font-mono font-medium dark:text-emerald-400">+{totalAdditions}</span>
          <span className="text-slate-300">/</span>
          <span className="text-rose-600 font-mono font-medium dark:text-rose-400">-{totalDeletions}</span>
        </div>
      </div>

      <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-5 space-y-2 dark:bg-[#141821] dark:border-slate-800/80">
        <div className="text-xs font-medium text-slate-500 uppercase tracking-wider dark:text-slate-400">CI Status</div>
        <div className="text-2xl font-bold text-slate-900 dark:text-slate-100">
          {checksCount === 0 ? 'No Checks' : `${passedChecks}/${checksCount} Passed`}
        </div>
        <div className="text-xs text-slate-500 dark:text-slate-400">Observed from GitHub Check Runs</div>
      </div>

      <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-5 space-y-2 dark:bg-[#141821] dark:border-slate-800/80">
        <div className="text-xs font-medium text-slate-500 uppercase tracking-wider dark:text-slate-400">Verification Scope</div>
        <div className="text-sm font-semibold text-slate-900 dark:text-slate-100">Local Fixture Engine Only</div>
        <div className="text-xs text-slate-500 dark:text-slate-400">
          Panther verifies bundled checkout fixtures in Checks; external PRs are read-only reviews.
        </div>
      </div>
    </div>
  );
}
