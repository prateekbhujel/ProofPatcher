import React from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Inbox, Info, XCircle } from 'lucide-react';

export interface BannerAction {
  label: string;
  href: string;
  external?: boolean;
}

interface AlertBannerProps {
  tone: 'error' | 'warn' | 'info';
  /** Where the problem lives, e.g. "muse · provider key". */
  where: string;
  /** Why it happened, in one line. */
  why: string;
  /** The single fix, in one line. */
  fix?: string;
  action?: BannerAction;
}

const toneStyles: Record<AlertBannerProps['tone'], { box: string; label: string; Icon: typeof Info }> = {
  error: {
    box: 'border-rose-300 dark:border-rose-800 bg-rose-50/70 dark:bg-rose-950/30',
    label: 'text-rose-700 dark:text-rose-300',
    Icon: XCircle,
  },
  warn: {
    box: 'border-amber-300 dark:border-amber-800 bg-amber-50/70 dark:bg-amber-950/30',
    label: 'text-amber-700 dark:text-amber-300',
    Icon: AlertTriangle,
  },
  info: {
    box: 'border-sky-300 dark:border-sky-800 bg-sky-50/70 dark:bg-sky-950/30',
    label: 'text-sky-700 dark:text-sky-300',
    Icon: Info,
  },
};

/**
 * The web twin of the terminal's where/why/fix reports: every failure names
 * its location, its cause, and the one fix. Never a bare red line.
 */
export function AlertBanner({ tone, where, why, fix, action }: AlertBannerProps) {
  const { box, label, Icon } = toneStyles[tone];
  return (
    <div role="alert" className={`rounded-lg border px-3 py-2.5 text-xs ${box}`}>
      <p className={`flex items-center gap-1.5 font-mono text-[11px] font-semibold uppercase tracking-wider ${label}`}>
        <Icon className="h-3.5 w-3.5" aria-hidden="true" />
        {where}
      </p>
      <p className="mt-1 text-slate-700 dark:text-slate-200">{why}</p>
      {fix && <p className="mt-0.5 text-slate-600 dark:text-slate-300">{fix}</p>}
      {action && (
        <p className="mt-1.5">
          {action.external ? (
            <a
              href={action.href}
              target="_blank"
              rel="noreferrer"
              className={`inline-flex items-center gap-1 font-semibold hover:underline ${label}`}
            >
              {action.label}
              <ArrowRight className="h-3 w-3" />
            </a>
          ) : (
            <Link href={action.href} className={`inline-flex items-center gap-1 font-semibold hover:underline ${label}`}>
              {action.label}
              <ArrowRight className="h-3 w-3" />
            </Link>
          )}
        </p>
      )}
    </div>
  );
}

interface EmptyStateProps {
  title: string;
  hint?: string;
  action?: BannerAction;
}

/** No dead ends: every empty list says what is missing and what to do. */
export function EmptyState({ title, hint, action }: EmptyStateProps) {
  return (
    <div className="rounded-lg border border-dashed border-slate-300 dark:border-slate-700 px-3 py-4 text-center">
      <Inbox className="mx-auto h-5 w-5 text-slate-400" aria-hidden="true" />
      <p className="mt-1.5 text-xs font-semibold text-slate-700 dark:text-slate-200">{title}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{hint}</p>}
      {action && (
        <p className="mt-2">
          <Link
            href={action.href}
            className="inline-flex items-center gap-1 text-xs font-semibold text-accent-700 dark:text-accent-300 hover:underline"
          >
            {action.label}
            <ArrowRight className="h-3 w-3" />
          </Link>
        </p>
      )}
    </div>
  );
}
