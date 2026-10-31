import React from 'react';
import { Link } from '@inertiajs/react';
import { Activity, BookOpen, CircleDot, GitPullRequest, Settings } from 'lucide-react';

const icons = [
  { href: '/issues', label: 'Issues & Tasks', detail: 'Workbench', Icon: CircleDot },
  { href: '/repositories', label: 'Pull Requests', detail: 'Reviews', Icon: GitPullRequest },
  { href: '/telemetry', label: 'Telemetry', detail: 'Usage', Icon: Activity },
  { href: '/settings', label: 'Settings', detail: 'Providers', Icon: Settings },
  { href: '/docs', label: 'Docs', detail: 'Guide', Icon: BookOpen },
];

/** Desktop-style quick-launch icons. Pure links to existing pages. */
export function DesktopIcons() {
  return (
    <section aria-label="Desktop">
      <ul className="grid grid-cols-3 gap-2 sm:grid-cols-5">
        {icons.map(({ href, label, detail, Icon }) => (
          <li key={href}>
            <Link
              href={href}
              aria-label={`Open ${label}`}
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
