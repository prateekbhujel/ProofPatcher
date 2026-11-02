import React from 'react';
import { Link } from '@inertiajs/react';
import { BrandMark } from '../ui/BrandMark';
import { ShinyText } from '../bits/ShinyText';

export type MenuBarNav = 'kernel' | 'overview' | 'repositories' | 'pulls' | 'checks' | 'issues' | 'settings' | 'docs' | 'telemetry' | 'terminal';

interface MenuBarProps {
  /** Center hint, e.g. the next step. Hidden on small screens. */
  center: string;
  connected: boolean;
  userLogin: string | null;
  /** While status loads, the pill shows an honest placeholder. */
  loading?: boolean;
  /** Current page, for the primary navigation. */
  activeNav?: MenuBarNav;
}

const PRIMARY_NAV: Array<{ key: string; href: string; label: string; match: MenuBarNav[] }> = [
  { key: 'kernel', href: '/', label: 'Kernel', match: ['kernel'] },
  { key: 'issues', href: '/issues', label: 'Issues', match: ['issues'] },
  { key: 'repositories', href: '/repositories', label: 'Pull Requests', match: ['repositories', 'pulls'] },
  { key: 'settings', href: '/settings', label: 'Settings', match: ['settings'] },
];

/** The one OS menu bar: desktop and every app page share it. */
export function MenuBar({ center, connected, userLogin, loading = false, activeNav = 'kernel' }: MenuBarProps) {
  return (
    <header className="sticky top-0 z-40 border-b border-[var(--border)] bg-[var(--bg-sidebar)]/95 backdrop-blur">
      <div className="mx-auto flex h-9 max-w-6xl items-center gap-3 px-4 text-xs sm:px-6">
        <Link
          href="/"
          aria-label="ProofPatcher Kernel home"
          className="flex shrink-0 items-center gap-2 font-semibold text-[var(--fg)]"
        >
          <BrandMark size={16} />
          <ShinyText
            text="ProofPatcher Kernel"
            color={typeof document !== 'undefined' && document.documentElement.classList.contains('dark') ? '#b5b5b5' : '#334155'}
            shineColor="#ffffff"
            speed={4}
            delay={1.5}
            shineWidth={28}
          />
        </Link>
        <nav aria-label="Primary" className="hidden shrink-0 items-center gap-1 sm:flex">
          {PRIMARY_NAV.map((item) => {
            const active = item.match.includes(activeNav);
            return (
              <Link
                key={item.key}
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={`rounded-md px-2 py-1 transition ${
                  active
                    ? 'bg-[var(--accent-soft)] font-semibold text-[var(--accent-text)]'
                    : 'text-[var(--fg-muted)] hover:bg-[var(--bg-hover)] hover:text-[var(--fg)]'
                }`}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
        <span className="hidden min-w-0 flex-1 truncate text-center font-mono text-[11px] text-[var(--fg-muted)] md:block">
          {center}
        </span>
        <span
          className={`ml-auto shrink-0 rounded-full border px-2.5 py-0.5 text-[11px] font-medium md:ml-0 ${
            connected
              ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
              : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300'
          }`}
        >
          {loading ? '···' : connected ? `Connected${userLogin ? ` as @${userLogin}` : ''}` : 'Not connected'}
        </span>
      </div>
    </header>
  );
}
