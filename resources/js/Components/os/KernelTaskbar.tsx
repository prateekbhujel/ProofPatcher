import React, { useEffect, useState } from 'react';

interface KernelTaskbarProps {
  connected: boolean;
  userLogin: string | null;
  reposCount: number;
  openIssues: number;
  openPulls: number;
  findingsCount: number;
  budgetText: string;
  workerLive: boolean;
  workerStatus: string;
}

function clockNow(): string {
  const d = new Date();
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  return `${hh}:${mm}`;
}

/** OS-style status bar pinned to the bottom of the Kernel home. */
export function KernelTaskbar({
  connected,
  userLogin,
  reposCount,
  openIssues,
  openPulls,
  findingsCount,
  budgetText,
  workerLive,
  workerStatus,
}: KernelTaskbarProps) {
  const [clock, setClock] = useState('--:--');

  useEffect(() => {
    setClock(clockNow());
    const t = window.setInterval(() => setClock(clockNow()), 30000);
    return () => window.clearInterval(t);
  }, []);

  return (
    <div className="fixed inset-x-0 bottom-0 z-40 border-t border-[var(--border)] bg-[var(--bg-sidebar)]/95 backdrop-blur">
      <div className="mx-auto flex h-10 max-w-6xl items-center gap-3 px-4 text-[11.5px] sm:px-6">
        <span className="flex shrink-0 items-center gap-2 font-semibold text-[var(--fg)]">
          <span
            className={`h-2 w-2 rounded-full ${workerLive ? 'bg-emerald-500' : 'bg-slate-400'}`}
            aria-label={workerLive ? 'autopilot running' : 'autopilot offline'}
          />
          ProofPatcher Kernel
        </span>
        <span className="hidden shrink-0 text-[var(--fg-muted)] md:inline">
          {connected ? `@${userLogin ?? '…'}` : 'not connected'}
        </span>
        <div className="flex min-w-0 flex-1 items-center gap-3 overflow-x-auto whitespace-nowrap text-[var(--fg-muted)]">
          <span>{reposCount} {reposCount === 1 ? 'repo' : 'repos'}</span>
          <span>{openIssues} {openIssues === 1 ? 'issue' : 'issues'}</span>
          <span>{openPulls} {openPulls === 1 ? 'pull' : 'pulls'}</span>
          <span>{findingsCount} {findingsCount === 1 ? 'finding' : 'findings'}</span>
          <span>{budgetText}</span>
          <span>{workerLive ? `autopilot ${workerStatus}` : 'autopilot offline'}</span>
        </div>
        <span className="shrink-0 font-mono text-[var(--fg-muted)] tabular-nums">{clock}</span>
      </div>
    </div>
  );
}
