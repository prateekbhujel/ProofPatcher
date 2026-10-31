import React from 'react';
import { Link } from '@inertiajs/react';
import { Activity, BookOpen, CircleDot, GitPullRequest, Settings, Terminal } from 'lucide-react';

const icons = [
  { href: '/issues', label: 'Issues & Tasks', detail: 'Workbench', Icon: CircleDot },
  { href: '/repositories', label: 'Pull Requests', detail: 'Reviews', Icon: GitPullRequest },
  { href: '/telemetry', label: 'Telemetry', detail: 'Usage', Icon: Activity },
  { href: '/terminal', label: 'Terminal', detail: 'Console', Icon: Terminal },
  { href: '/settings', label: 'Settings', detail: 'Providers', Icon: Settings },
  { href: '/docs', label: 'Docs', detail: 'Guide', Icon: BookOpen },
];

/**
 * Desktop-style quick-launch icons. Pure links to existing pages.
 * `grid` is the classic card row; `rail` is the slim desktop icon column.
 * On the desktop, `onOpenApp` intercepts clicks into windows; without it
 * (and without JS) the links navigate normally.
 */
export function DesktopIcons({
  layout = 'grid',
  onOpenApp,
}: {
  layout?: 'grid' | 'rail';
  onOpenApp?: (href: string) => void;
}) {
  const intercept = (href: string) => (onOpenApp ? {
    onClick: (event: React.MouseEvent) => {
      event.preventDefault();
      onOpenApp(href);
    },
  } : {});
  if (layout === 'rail') {
    return (
      <ul className="flex gap-1 sm:flex-col" aria-label="Desktop apps">
        {icons.map(({ href, label, detail, Icon }) => (
          <li key={href}>
            <Link
              href={href}
              aria-label={`Open ${label}`}
              title={`${label} · ${detail}`}
              {...intercept(href)}
              className="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition hover:bg-[var(--bg-hover)]"
            >
              <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--accent-soft)]">
                <Icon className="h-4 w-4 text-[var(--accent-text)]" />
              </span>
              <span className="hidden sm:block">
                <span className="block whitespace-nowrap text-xs font-semibold text-[var(--fg)]">{label}</span>
                <span className="block text-[11px] text-[var(--fg-muted)]">{detail}</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    );
  }

  return (
    <section aria-label="Desktop">
      <ul className="grid grid-cols-3 gap-2 sm:grid-cols-6">
        {icons.map(({ href, label, detail, Icon }) => (
          <li key={href}>
            <Link
              href={href}
              aria-label={`Open ${label}`}
              {...intercept(href)}
              className="flex flex-col items-center gap-1.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] px-2 py-4 text-center shadow-xs hover:border-[var(--accent)] transition"
            >
              <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--accent-soft)]">
                <Icon className="h-[18px] w-[18px] text-[var(--accent-text)]" />
              </span>
              <span className="text-xs font-semibold text-slate-900 dark:text-slate-100">{label}</span>
              <span className="text-[11px] text-slate-500 dark:text-slate-400">{detail}</span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
