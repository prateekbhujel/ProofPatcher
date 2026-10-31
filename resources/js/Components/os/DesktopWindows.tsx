import React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { KernelTerminal, TerminalLine } from './KernelTerminal';
import { AlertBanner, EmptyState } from '../ui/AlertBanner';

export interface KernelStage {
  key: 'intake' | 'propose' | 'verify' | 'publish';
  title: string;
  detail: string;
  state: 'done' | 'active' | 'todo';
  href: string;
}

export interface KernelNextStep {
  key: string;
  title: string;
  detail: string;
  href: string;
  cta: string;
}

export interface KernelFinding {
  id: number;
  repository: string;
  pull_number: number;
  status: string;
  summary: string | null;
  risk_level: string | null;
  recommendation: string | null;
  reviewed_at: string | null;
}

export interface KernelVerification {
  id: string;
  decision: string;
  isSimulated: boolean | null;
  receiptSha256: string | null;
  repository: string | null;
}

function stageDot(state: KernelStage['state']) {
  if (state === 'done') {
    return (
      <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950">
        <Check className="h-3 w-3 text-emerald-700 dark:text-emerald-300" />
      </span>
    );
  }
  if (state === 'active') {
    return <span className="inline-block h-2.5 w-2.5 rounded-full bg-accent-600 dark:bg-accent-400" aria-label="current stage" />;
  }
  return <span className="inline-block h-2.5 w-2.5 rounded-full border border-slate-300 dark:border-slate-600" aria-label="upcoming stage" />;
}

function NextCta({ step }: { step: KernelNextStep }) {
  const external = step.href.startsWith('http');
  const className =
    'inline-flex shrink-0 items-center gap-1.5 px-4 py-2 rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-xs font-semibold hover:bg-slate-800 transition';
  const body = (
    <>
      <span>{step.cta}</span>
      <ArrowRight className="h-3.5 w-3.5" />
    </>
  );
  if (external) {
    return (
      <a href={step.href} target="_blank" rel="noreferrer" className={className}>
        {body}
      </a>
    );
  }
  return (
    <Link href={step.href} className={className}>
      {body}
    </Link>
  );
}

/** Mission window: the one next step plus the four pipeline stages. */
export function MissionContent({ nextStep, stages }: { nextStep: KernelNextStep; stages: KernelStage[] }) {
  return (
    <div className="space-y-3">
      <div>
        <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Start here: {nextStep.title}</h2>
        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{nextStep.detail}</p>
        <p className="mt-2">
          <NextCta step={nextStep} />
        </p>
      </div>
      <ol className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
        {stages.map((stage, index) => (
          <li key={stage.key}>
            <Link
              href={stage.href}
              className="flex h-full items-start gap-2 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/50 px-2.5 py-2 hover:border-slate-300 dark:hover:border-slate-700 transition"
            >
              <span className="mt-0.5 text-[11px] font-mono text-slate-400">{index + 1}</span>
              <span className="mt-1">{stageDot(stage.state)}</span>
              <span className="min-w-0">
                <span className="block text-xs font-semibold text-slate-900 dark:text-slate-100">{stage.title}</span>
                <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{stage.detail}</span>
              </span>
            </Link>
          </li>
        ))}
      </ol>
    </div>
  );
}

/** Console window: the streaming kernel transcript. */
export function ConsoleContent({ lines }: { lines: TerminalLine[] }) {
  return <KernelTerminal lines={lines} />;
}

/** Findings window: recent reviews plus the last verification. */
export function FindingsContent({
  findings,
  connected,
  lastVerification,
  publishedPr,
}: {
  findings: KernelFinding[];
  connected: boolean;
  lastVerification: KernelVerification | null;
  publishedPr: { prNumber: number | null; prUrl: string | null; repository: string | null } | null;
}) {
  return (
    <div className="space-y-4">
      <div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Recent review findings</h2>
          <Link href="/repositories" className="text-xs text-accent-700 dark:text-accent-300 hover:underline">
            Open reviews
          </Link>
        </div>
        {findings.length === 0 ? (
          <div className="mt-2">
            <EmptyState
              title="No automatic reviews yet"
              hint={
                connected
                  ? 'Enable a public repository in Settings and start autopilot.'
                  : 'Connect GitHub to enable automatic reviews.'
              }
              action={
                connected
                  ? { label: 'Open Settings', href: '/settings' }
                  : { label: 'Continue with GitHub', href: '/issues' }
              }
            />
          </div>
        ) : (
          <ul className="mt-1 divide-y divide-slate-100 dark:divide-slate-800">
            {findings.map((finding) => (
              <li key={finding.id} className="py-2 text-xs">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-medium text-slate-900 dark:text-slate-100 break-all">
                    {finding.repository}#{finding.pull_number}
                  </span>
                  <span className="text-[11px] text-slate-500 dark:text-slate-400">
                    {finding.status === 'completed'
                      ? `${(finding.recommendation || 'no recommendation').replace(/_/g, ' ')}${finding.risk_level ? ` · ${finding.risk_level} risk` : ''}`
                      : finding.status.replace(/_/g, ' ')}
                  </span>
                </div>
                {finding.summary && (
                  <p className="mt-0.5 text-slate-500 dark:text-slate-400 line-clamp-2">{finding.summary}</p>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      <div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Last verification</h2>
          <Link href="/issues" className="text-xs text-accent-700 dark:text-accent-300 hover:underline">
            Open workbench
          </Link>
        </div>
        {!lastVerification ? (
          <div className="mt-2">
            <EmptyState
              title="Nothing checked yet"
              hint="Proposals stay unverified until the checks run."
              action={{ label: 'Open the workbench', href: '/issues' }}
            />
          </div>
        ) : (
          <div className="mt-2 space-y-1.5 text-xs">
            <div className="flex flex-wrap items-center gap-2">
              <span
                className={`px-2 py-0.5 rounded-full text-[11px] font-semibold border ${
                  lastVerification.decision === 'VERIFIED'
                    ? 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-800'
                    : 'bg-rose-50 text-rose-800 border-rose-200 dark:bg-rose-950/50 dark:text-rose-300 dark:border-rose-800'
                }`}
              >
                {lastVerification.decision}
              </span>
              {lastVerification.repository && (
                <span className="text-slate-500 dark:text-slate-400">{lastVerification.repository}</span>
              )}
            </div>
            <p className="text-slate-500 dark:text-slate-400">
              {lastVerification.isSimulated === false
                ? 'Isolated sandbox run.'
                : lastVerification.isSimulated === true
                  ? 'Host run — local evidence only, cannot authorize publishing.'
                  : 'Run details unavailable.'}
            </p>
            {lastVerification.receiptSha256 && (
              <p className="font-mono text-[11px] text-slate-500 dark:text-slate-400 break-all">
                Receipt {lastVerification.receiptSha256.slice(0, 24)}…
              </p>
            )}
            {publishedPr?.prUrl && (
              <p>
                <a
                  href={publishedPr.prUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="font-medium text-accent-700 dark:text-accent-300 hover:underline"
                >
                  Draft PR{publishedPr.prNumber ? ` #${publishedPr.prNumber}` : ''} on GitHub
                </a>
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

export interface EngineProps {
  connected: boolean;
  reposCount: number;
  selectedRepo: string | null;
  openIssues: number;
  openPulls: number;
  provider: { label: string; model: string; keyReady: boolean };
  budget: { attempts: number; maxAttempts: number; remainingMicros: number; canRequest: boolean } | null;
  autopilotCaps?: { maxAttempts: number | null; maxReservedDollars: number | null } | null;
  worker: { isLive: boolean; status: string };
  autoStats: { totalReviews: number; completed: number; failed: number; skippedPrivate: number; skippedBudget: number };
}

/** Engine window: adapter, Muse, budget, autopilot — with banners, not puzzles. */
export function EngineContent({
  connected,
  reposCount,
  selectedRepo,
  openIssues,
  openPulls,
  provider,
  budget,
  autopilotCaps,
  worker,
  autoStats,
}: EngineProps) {
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="flex items-center gap-3 text-xs">
          <Link href="/telemetry" className="text-accent-700 dark:text-accent-300 hover:underline">
            Open telemetry
          </Link>
          <Link href="/settings" className="text-accent-700 dark:text-accent-300 hover:underline">
            Open Settings
          </Link>
        </span>
      </div>

      {!connected && (
        <AlertBanner
          tone="info"
          where="github adapter"
          why="Not connected — the pipeline has no repositories to watch."
          fix="Link your account, then pick a repository to work on."
          action={{ label: 'Continue with GitHub', href: '/issues' }}
        />
      )}
      {!provider.keyReady && (
        <AlertBanner
          tone="warn"
          where="muse · provider key"
          why="Key missing — reviews cannot run without a provider key."
          fix="Add a Baseten or Meta key in Settings → Providers."
          action={{ label: 'Open Settings', href: '/settings' }}
        />
      )}

      <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-xs sm:grid-cols-2">
        <div>
          <dt className="text-slate-500 dark:text-slate-400">Adapter</dt>
          <dd className="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
            {connected
              ? `GitHub · ${reposCount} ${reposCount === 1 ? 'repo' : 'repos'}${selectedRepo ? ` · ${selectedRepo}` : ''}`
              : 'GitHub · not connected'}
          </dd>
          {connected && (
            <dd className="mt-0.5 text-slate-500 dark:text-slate-400">
              {openIssues} {openIssues === 1 ? 'issue' : 'issues'} · {openPulls} {openPulls === 1 ? 'pull request' : 'pull requests'} loaded
            </dd>
          )}
        </div>
        <div>
          <dt className="text-slate-500 dark:text-slate-400">Muse</dt>
          <dd className="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
            {provider.label} · <span className="font-mono text-[11px]">{provider.model}</span>
          </dd>
          <dd className={`mt-0.5 ${provider.keyReady ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'}`}>
            {provider.keyReady ? 'Key ready' : 'Key missing — add one in Settings'}
          </dd>
        </div>
        <div>
          <dt className="text-slate-500 dark:text-slate-400">Request budget</dt>
          <dd className="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
            {budget
              ? `${budget.attempts} of ${budget.maxAttempts} attempts · $${(budget.remainingMicros / 1000000).toFixed(2)} left`
              : 'Unavailable'}
          </dd>
          {budget && !budget.canRequest && (
            <dd className="mt-0.5 text-amber-700 dark:text-amber-400">Local limit reached</dd>
          )}
          {autopilotCaps && (autopilotCaps.maxAttempts != null || autopilotCaps.maxReservedDollars != null) && (
            <dd className="mt-0.5 text-slate-500 dark:text-slate-400">
              Autopilot capped at
              {autopilotCaps.maxAttempts != null ? ` ${autopilotCaps.maxAttempts} attempts` : ''}
              {autopilotCaps.maxAttempts != null && autopilotCaps.maxReservedDollars != null ? ' and' : ''}
              {autopilotCaps.maxReservedDollars != null ? ` $${autopilotCaps.maxReservedDollars.toFixed(2)}` : ''}
            </dd>
          )}
        </div>
        <div>
          <dt className="text-slate-500 dark:text-slate-400">Autopilot</dt>
          <dd className="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
            {worker.isLive ? `Running (${worker.status})` : 'Offline'}
          </dd>
          <dd className="mt-0.5 text-slate-500 dark:text-slate-400">
            {autoStats.completed} reviewed · {autoStats.failed} failed
          </dd>
        </div>
      </dl>
      {!worker.isLive && (
        <p className="text-xs text-slate-500 dark:text-slate-400">
          Start autopilot for automatic reviews:{' '}
          <code className="select-all font-mono text-[11px]">php artisan review:poll --daemon</code>
        </p>
      )}
    </div>
  );
}
