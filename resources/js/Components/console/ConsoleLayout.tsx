import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { BrandMark } from '../ui/BrandMark';
import { ThemeToggle } from '../ThemeToggle';
import { CONSOLE_NAV, ConsoleNavKey, isNavActive } from './primitives';
import { useOsStatus } from './useOsStatus';

interface ConsoleLayoutProps {
  activeNav?: ConsoleNavKey;
  title: string;
  children: React.ReactNode;
}

/**
 * The console frame: left rail (brand, navigation, connection), top status
 * strip, dense content. Every page runs inside it. Embedded views
 * (?embed=1 or inside a frame) render bare content only.
 */
export function ConsoleLayout({ activeNav = 'kernel', title, children }: ConsoleLayoutProps) {
  const { embed } = usePage().props as { embed?: boolean };
  const status = useOsStatus();
  const [framed, setFramed] = useState(false);
  useEffect(() => {
    try {
      setFramed(window.self !== window.top);
    } catch {
      setFramed(true);
    }
  }, []);

  if (embed === true || framed) {
    return <div className="min-h-screen bg-[var(--bg)] text-[var(--fg)]"><main className="pp-page-enter p-3">{children}</main></div>;
  }

  const connected = status?.connected ?? false;
  const disconnect = () => {
    const path = typeof window !== 'undefined' ? window.location.pathname : '/';
    router.post('/github/device/disconnect', { redirect_to: path });
  };

  return (
    <div className="flex min-h-screen bg-[var(--bg)] text-[var(--fg)]">
      <aside className="sticky top-0 hidden h-screen w-[204px] shrink-0 flex-col border-r border-[var(--border)] bg-[var(--bg-sidebar)] md:flex" aria-label="Console">
        <Link href="/" className="flex items-center gap-2 border-b border-[var(--border)] px-3 py-2.5" aria-label="ProofPatcher console home">
          <BrandMark size={20} />
          <span className="min-w-0">
            <span className="block truncate text-[13px] font-bold leading-tight">proofpatcher</span>
          </span>
        </Link>

        <nav className="flex-1 overflow-y-auto px-2 py-2" aria-label="Primary">
          {CONSOLE_NAV.map((group) => (
            <div key={group.section} className="mb-3">
              <p className="px-1.5 pb-1 text-[10px] font-semibold uppercase tracking-widest text-[var(--fg-subtle)]">{group.section}</p>
              <ul className="space-y-px">
                {group.items.map((item) => {
                  const active = isNavActive(item.key, activeNav);
                  return (
                    <li key={item.key}>
                      <Link
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={`block px-1.5 py-1 text-xs ${active ? 'bg-[var(--accent-soft)] font-semibold text-[var(--accent-text)]' : 'text-[var(--fg-muted)] hover:bg-[var(--bg-hover)] hover:text-[var(--fg)]'}`}
                      >
                        {item.label}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </nav>

        <div className="border-t border-[var(--border)] px-3 py-2 text-[11px]">
          <p className="flex items-center gap-1.5">
            <span className={`h-1.5 w-1.5 rounded-full ${status === null ? 'bg-slate-400' : connected ? 'bg-emerald-500' : 'bg-slate-400'}`} aria-hidden="true" />
            <span className="truncate">{status === null ? 'loading…' : connected ? `@${status.userLogin ?? 'connected'}` : 'not connected'}</span>
          </p>
          {status?.selectedRepo && <p className="mt-0.5 truncate text-[var(--fg-muted)]">{status.selectedRepo}</p>}
          <div className="mt-1.5 flex items-center justify-between">
            <ThemeToggle />
            <button type="button" onClick={disconnect} className="text-[var(--fg-muted)] underline-offset-2 hover:text-rose-600 hover:underline dark:hover:text-rose-400">
              disconnect
            </button>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-9 items-center gap-3 border-b border-[var(--border)] bg-[var(--bg-sidebar)]/95 px-3 text-xs backdrop-blur">
          <Link href="/" className="flex shrink-0 items-center gap-1.5 font-bold md:hidden" aria-label="ProofPatcher console home">
            <BrandMark size={16} /> pp
          </Link>
          <span className="shrink-0 font-semibold">{title}</span>
          <span className="hidden min-w-0 flex-1 truncate text-[var(--fg-muted)] lg:block">
            {status ? `next: ${status.next.title} — ${status.next.detail}` : '···'}
          </span>
          <span className="ml-auto shrink-0 text-[var(--fg-muted)]">
            {status === null ? '···' : `${status.openIssues} issues · ${status.openPulls} pulls · ${status.budgetText ?? 'budget n/a'} · ${status.workerLive ? 'autopilot live' : 'autopilot off'}`}
          </span>
        </header>
        <nav className="flex gap-1 overflow-x-auto border-b border-[var(--border)] px-2 py-1 text-xs md:hidden" aria-label="Primary">
          {CONSOLE_NAV.flatMap((g) => g.items).map((item) => (
            <Link key={item.key} href={item.href} aria-current={isNavActive(item.key, activeNav) ? 'page' : undefined} className={`shrink-0 px-1.5 py-0.5 ${isNavActive(item.key, activeNav) ? 'bg-[var(--accent-soft)] font-semibold text-[var(--accent-text)]' : 'text-[var(--fg-muted)]'}`}>
              {item.label}
            </Link>
          ))}
        </nav>
        <main className="pp-page-enter min-w-0 flex-1 p-3 sm:p-4">{children}</main>
      </div>
    </div>
  );
}
