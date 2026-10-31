import React from 'react';
import { Link } from '@inertiajs/react';

interface AppWindowProps {
  title: string;
  children: React.ReactNode;
}

/**
 * A maximized app window: every app page runs inside one. The single close
 * control returns to the desktop — there is no fake minimize/maximize.
 */
export function AppWindow({ title, children }: AppWindowProps) {
  return (
    <section
      aria-label={`${title} app window`}
      className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#10141d] shadow-lg"
    >
      <div className="flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-[#0b0f16] px-3 py-2">
        <Link
          href="/"
          aria-label={`Close ${title} and return to desktop`}
          className="h-2.5 w-2.5 rounded-full bg-rose-500/80 hover:bg-rose-500"
        />
        <span className="ml-1 truncate font-mono text-[11px] font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          {title}
        </span>
      </div>
      <div className="p-4 sm:p-6">{children}</div>
    </section>
  );
}
