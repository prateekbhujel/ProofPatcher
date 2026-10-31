import React, { useEffect, useState } from 'react';
import { LayoutGrid, Settings } from 'lucide-react';

export interface TaskbarWindow {
  id: string;
  title: string;
  minimized: boolean;
  front: boolean;
}

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
  /** Start-menu wiring. Absent on non-desktop surfaces. */
  startOpen?: boolean;
  onStart?: () => void;
  /** Open desktop windows, rendered as task buttons. */
  windows?: TaskbarWindow[];
  onToggleWindow?: (id: string) => void;
  /** While status loads, counts show placeholders instead of claiming offline. */
  loading?: boolean;
  /** On the desktop, the settings gear opens a window instead of navigating. */
  onOpenApp?: (href: string) => void;
}

function clockNow(): string {
  const d = new Date();
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  return `${hh}:${mm}`;
}

/** OS-style status bar pinned to the bottom of the Kernel desktop. */
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
  startOpen = false,
  onStart,
  windows = [],
  onToggleWindow,
  loading = false,
  onOpenApp,
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
        {onStart && (
          <button
            type="button"
            onClick={onStart}
            aria-expanded={startOpen}
            aria-label="Open start menu"
            className={`flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 font-semibold transition ${
              startOpen ? 'bg-[var(--accent-soft)] text-[var(--accent-text)]' : 'text-[var(--fg)] hover:bg-[var(--bg-hover)]'
            }`}
          >
            <LayoutGrid className="h-3.5 w-3.5" aria-hidden="true" />
            start
          </button>
        )}
        <span className="flex shrink-0 items-center gap-2 font-semibold text-[var(--fg)]">
          <span
            className={`h-2 w-2 rounded-full ${workerLive ? 'bg-emerald-500 os-live-dot' : 'bg-slate-400'}`}
            aria-label={loading ? 'status loading' : workerLive ? 'autopilot running' : 'autopilot offline'}
          />
          ProofPatcher Kernel
        </span>
        {windows.length > 0 && onToggleWindow && (
          <span className="hidden shrink-0 items-center gap-1 sm:flex" role="group" aria-label="Open windows">
            {windows.map((win) => (
              <button
                key={win.id}
                type="button"
                onClick={() => onToggleWindow(win.id)}
                aria-pressed={win.front}
                aria-label={`Toggle ${win.title} window`}
                className={`rounded-md px-2 py-1 font-mono text-[11px] uppercase tracking-wider transition ${
                  win.front
                    ? 'bg-[var(--accent-soft)] text-[var(--accent-text)]'
                    : win.minimized
                      ? 'text-[var(--fg-subtle)] hover:bg-[var(--bg-hover)]'
                      : 'text-[var(--fg-muted)] hover:bg-[var(--bg-hover)]'
                }`}
              >
                {win.title}
              </button>
            ))}
          </span>
        )}
        <span className="hidden shrink-0 text-[var(--fg-muted)] md:inline">
          {loading ? '···' : connected ? `@${userLogin ?? '…'}` : 'not connected'}
        </span>
        <div className="flex min-w-0 flex-1 items-center gap-3 overflow-x-auto whitespace-nowrap text-[var(--fg-muted)]">
          {loading ? (
            <span className="shrink-0">···</span>
          ) : (
            <>
              <span className="shrink-0">{reposCount} {reposCount === 1 ? 'repo' : 'repos'}</span>
              <span className="shrink-0">{openIssues} {openIssues === 1 ? 'issue' : 'issues'}</span>
              <span className="shrink-0">{openPulls} {openPulls === 1 ? 'pull' : 'pulls'}</span>
              <span className="shrink-0">{findingsCount} {findingsCount === 1 ? 'finding' : 'findings'}</span>
              <span className="shrink-0">{budgetText}</span>
              <span className="shrink-0">{workerLive ? `autopilot ${workerStatus}` : 'autopilot offline'}</span>
            </>
          )}
        </div>
        <span className="flex shrink-0 items-center gap-2">
          <a
            href="/settings"
            aria-label="Open Settings"
            title="Settings"
            onClick={
              onOpenApp
                ? (event: React.MouseEvent) => {
                    event.preventDefault();
                    onOpenApp('/settings');
                  }
                : undefined
            }
            className="text-[var(--fg-muted)] hover:text-[var(--fg)] transition"
          >
            <Settings className="h-3.5 w-3.5" aria-hidden="true" />
          </a>
          <span className="font-mono text-[var(--fg-muted)] tabular-nums">{clock}</span>
        </span>
      </div>
    </div>
  );
}
