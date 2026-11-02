import React from 'react';

export type ConsoleNavKey =
  | 'kernel' | 'overview' | 'repositories' | 'pulls' | 'checks'
  | 'issues' | 'settings' | 'docs' | 'telemetry' | 'terminal';

export interface ConsoleNavItem {
  href: string;
  label: string;
  key: ConsoleNavKey;
}

/** Single navigation model for the console rail. */
export const CONSOLE_NAV: Array<{ section: string; items: ConsoleNavItem[] }> = [
  {
    section: 'work',
    items: [
      { href: '/', label: 'console', key: 'kernel' },
      { href: '/issues', label: 'issues', key: 'issues' },
      { href: '/repositories', label: 'pull requests', key: 'repositories' },
      { href: '/checks', label: 'checks', key: 'checks' },
    ],
  },
  {
    section: 'system',
    items: [
      { href: '/overview', label: 'overview', key: 'overview' },
      { href: '/telemetry', label: 'telemetry', key: 'telemetry' },
      { href: '/terminal', label: 'terminal', key: 'terminal' },
      { href: '/docs', label: 'docs', key: 'docs' },
      { href: '/settings', label: 'settings', key: 'settings' },
    ],
  },
];

export function isNavActive(itemKey: ConsoleNavKey, activeNav: ConsoleNavKey): boolean {
  if (itemKey === 'repositories') return activeNav === 'repositories' || activeNav === 'pulls';
  return itemKey === activeNav;
}

/** Flat bordered panel: the console's one container primitive. */
export function Panel({
  title,
  right,
  children,
  className = '',
}: {
  title?: string;
  right?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section className={`border border-[var(--border)] bg-[var(--bg-raised)] ${className}`}>
      {title !== undefined && (
        <header className="flex items-center justify-between gap-3 border-b border-[var(--border)] px-3 py-1.5">
          <h2 className="text-[11px] font-semibold uppercase tracking-wider text-[var(--fg-muted)]">{title}</h2>
          {right}
        </header>
      )}
      <div className="p-3">{children}</div>
    </section>
  );
}

/** One dense key/value row. */
export function Stat({ label, value, tone = 'default' }: { label: string; value: string; tone?: 'default' | 'ok' | 'warn' | 'danger' }) {
  const toneClass =
    tone === 'ok' ? 'text-emerald-700 dark:text-emerald-400'
    : tone === 'warn' ? 'text-amber-700 dark:text-amber-400'
    : tone === 'danger' ? 'text-rose-700 dark:text-rose-400'
    : 'text-[var(--fg)]';
  return (
    <div className="flex items-baseline justify-between gap-3 py-0.5 text-xs">
      <span className="text-[var(--fg-muted)]">{label}</span>
      <span className={`text-right font-medium ${toneClass}`}>{value}</span>
    </div>
  );
}
