import React, { useEffect, useState } from 'react';
import { APP_FOLDERS } from './apps';
import { AppWindow } from './AppWindow';
import { KernelTaskbar } from './KernelTaskbar';
import { MenuBar } from './MenuBar';
import { StartMenu } from './StartMenu';
import { useOsStatus } from './useOsStatus';

interface OsShellProps {
  /** App title for the window chrome and menu bar. */
  appId: string;
  /** Fullscreen apps (the workbench) skip the window frame, not the chrome. */
  fullscreen?: boolean;
  /** Embedded in a desktop window: bare content, no nested chrome. */
  embed?: boolean;
  children: React.ReactNode;
}

/**
 * The OS frame around every app page: menu bar, app window, taskbar, and
 * start menu. Status loads client-side; until then the chrome shows honest
 * placeholders instead of claiming offline.
 */
export function OsShell({ appId, fullscreen = false, embed = false, children }: OsShellProps) {
  const status = useOsStatus();
  const [startOpen, setStartOpen] = useState(false);
  // In-iframe navigation can drop ?embed=1; the runtime check keeps nested
  // chrome away no matter how the page was reached.
  const [framed, setFramed] = useState(false);
  useEffect(() => {
    try {
      setFramed(window.self !== window.top);
    } catch {
      setFramed(true);
    }
  }, []);

  if (embed || framed) {
    return (
      <div className="min-h-screen bg-[var(--bg)] text-[var(--fg)]">
        {fullscreen ? (
          <main className="pp-page-enter min-h-screen w-full">{children}</main>
        ) : (
          <main className="pp-page-enter mx-auto w-full max-w-6xl px-4 py-6 sm:px-6">{children}</main>
        )}
      </div>
    );
  }

  const center = status ? `→ ${status.next.title} — ${status.next.detail}` : appId;
  const statusLine = status
    ? `${status.budgetText ?? 'budget n/a'} · ${status.workerLive ? `autopilot ${status.workerStatus}` : 'autopilot offline'} · ${
        status.connected ? `@${status.userLogin ?? '…'}` : 'not connected'
      }`
    : '···';

  return (
    <div className={fullscreen ? 'min-h-screen bg-[var(--bg)] text-[var(--fg)]' : 'os-wallpaper min-h-screen'}>
      {!fullscreen && <div className="os-grid-overlay pointer-events-none fixed inset-0" aria-hidden="true" />}
      <div className="relative flex min-h-screen flex-col">
        <MenuBar
          center={center}
          connected={status?.connected ?? false}
          userLogin={status?.userLogin ?? null}
          loading={status === null}
        />
        {fullscreen ? (
          <main className="pp-page-enter min-h-screen w-full flex-1">{children}</main>
        ) : (
          <main className="pp-page-enter mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6">
            <AppWindow title={appId}>{children}</AppWindow>
          </main>
        )}
        <div className="h-10" aria-hidden="true" />
      </div>
      <StartMenu
        open={startOpen}
        apps={[]}
        folders={APP_FOLDERS}
        windows={[]}
        nextTitle={status?.next.title ?? '···'}
        nextDetail={status?.next.detail ?? '···'}
        nextHref={status?.next.href ?? '/'}
        nextCta={status?.next.cta ?? '···'}
        statusLine={statusLine}
        onToggleWindow={() => {}}
        onClose={() => setStartOpen(false)}
      />
      <KernelTaskbar
        connected={status?.connected ?? false}
        userLogin={status?.userLogin ?? null}
        reposCount={status?.reposCount ?? 0}
        openIssues={status?.openIssues ?? 0}
        openPulls={status?.openPulls ?? 0}
        findingsCount={status?.findingsCount ?? 0}
        budgetText={status?.budgetText ?? '···'}
        workerLive={status?.workerLive ?? false}
        workerStatus={status?.workerStatus ?? '···'}
        loading={status === null}
        startOpen={startOpen}
        onStart={() => setStartOpen((v) => !v)}
      />
    </div>
  );
}
