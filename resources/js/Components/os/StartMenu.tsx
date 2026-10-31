import React, { useEffect } from 'react';
import { Link, router } from '@inertiajs/react';
import { AppWindow, ArrowRight, LayoutGrid, Power } from 'lucide-react';
import { useReducedMotion } from './useReducedMotion';

export interface StartMenuApp {
  href: string;
  label: string;
  detail: string;
}

export interface StartMenuFolder {
  title: string;
  apps: StartMenuApp[];
}

export interface StartMenuWindow {
  id: string;
  title: string;
  open: boolean;
  minimized: boolean;
}

interface StartMenuProps {
  open: boolean;
  apps: StartMenuApp[];
  folders?: StartMenuFolder[];
  windows: StartMenuWindow[];
  nextTitle: string;
  nextDetail: string;
  nextHref: string;
  nextCta: string;
  statusLine: string;
  onToggleWindow: (id: string) => void;
  onClose: () => void;
  /** On the desktop, app clicks open windows instead of navigating. */
  onOpenApp?: (href: string) => void;
}

function AppLink({ app, onOpenApp }: { app: StartMenuApp; onOpenApp?: (href: string) => void }) {
  return (
    <Link
      href={app.href}
      onClick={
        onOpenApp
          ? (event: React.MouseEvent) => {
              event.preventDefault();
              onOpenApp(app.href);
            }
          : undefined
      }
      className="flex items-center justify-between gap-2 rounded-lg px-2.5 py-2 hover:bg-slate-100 dark:hover:bg-slate-800"
    >
      <span>
        <span className="block text-xs font-semibold text-slate-900 dark:text-slate-100">{app.label}</span>
        <span className="block text-[11px] text-slate-500 dark:text-slate-400">{app.detail}</span>
      </span>
      <ArrowRight className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
    </Link>
  );
}

/** The taskbar launcher: apps, window toggles, the next step, and status. */
export function StartMenu({
  open,
  apps,
  folders = [],
  windows,
  nextTitle,
  nextDetail,
  nextHref,
  nextCta,
  statusLine,
  onToggleWindow,
  onClose,
  onOpenApp,
}: StartMenuProps) {
  const reducedMotion = useReducedMotion();

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50" role="dialog" aria-label="Start menu">
      <button type="button" aria-label="Close start menu" onClick={onClose} className="absolute inset-0 cursor-default bg-black/30" />
      <div
        className={`absolute bottom-12 left-2 w-[min(380px,calc(100vw-1rem))] overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#10141d] shadow-xl sm:left-4 ${
          reducedMotion ? '' : 'os-menu-enter'
        }`}
      >
        <div className="border-b border-slate-200 dark:border-slate-800 px-4 py-2.5">
          <p className="font-mono text-[11px] font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">
            Start
          </p>
        </div>
        <div className="max-h-[60vh] space-y-4 overflow-y-auto p-4">
          <div>
            <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
              <LayoutGrid className="h-3 w-3" aria-hidden="true" />
              Apps
            </p>
            {apps.length > 0 && (
              <ul className="mt-1.5 grid grid-cols-1 gap-1">
                {apps.map((app) => (
                  <li key={app.href}>
                    <AppLink app={app} onOpenApp={onOpenApp} />
                  </li>
                ))}
              </ul>
            )}
            {folders.map((folder) => (
              <div key={folder.title} className="mt-2">
                <p className="px-2.5 font-mono text-[10px] uppercase tracking-widest text-slate-500 dark:text-slate-500">
                  {folder.title}
                </p>
                <ul className="mt-1 grid grid-cols-1 gap-1">
                  {folder.apps.map((app) => (
                    <li key={app.href}>
                      <AppLink app={app} onOpenApp={onOpenApp} />
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
          {windows.length > 0 && (
            <div>
              <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                <AppWindow className="h-3 w-3" aria-hidden="true" />
                Windows
              </p>
              <ul className="mt-1.5 grid grid-cols-2 gap-1">
                {windows.map((win) => (
                  <li key={win.id}>
                    <button
                      type="button"
                      onClick={() => onToggleWindow(win.id)}
                      aria-pressed={win.open && !win.minimized}
                      className="flex w-full items-center gap-2 rounded-lg border border-slate-200 dark:border-slate-700 px-2.5 py-2 text-left text-xs font-medium text-slate-700 dark:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600"
                    >
                      <span
                        className={`h-1.5 w-1.5 shrink-0 rounded-full ${win.open && !win.minimized ? 'bg-emerald-500' : 'bg-slate-400'}`}
                        aria-hidden="true"
                      />
                      <span className="truncate">{win.title}</span>
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}
          <div className="rounded-lg bg-slate-100 dark:bg-slate-800/70 p-3">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
              Next: {nextTitle}
            </p>
            <p className="mt-0.5 truncate text-xs text-slate-600 dark:text-slate-300">{nextDetail}</p>
            <p className="mt-1.5">
              <Link
                href={nextHref}
                className="inline-flex items-center gap-1 text-xs font-semibold text-accent-700 dark:text-accent-300 hover:underline"
              >
                {nextCta}
                <ArrowRight className="h-3 w-3" />
              </Link>
            </p>
          </div>
          <p className="truncate font-mono text-[11px] text-slate-500 dark:text-slate-400">{statusLine}</p>
        </div>
        <div className="flex items-center justify-between gap-2 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/60 px-4 py-2">
          <span className="font-mono text-[10px] uppercase tracking-widest text-slate-400">Session</span>
          <button
            type="button"
            onClick={() => {
              onClose();
              router.post('/github/device/disconnect', { redirect_to: '/' });
            }}
            className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition"
          >
            <Power className="h-3.5 w-3.5" aria-hidden="true" />
            Disconnect GitHub
          </button>
        </div>
      </div>
    </div>
  );
}
