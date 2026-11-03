import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, ExternalLink } from 'lucide-react';
import { AppLayout } from '../Components/AppLayout';
import { Panel, Stat } from '../Components/console/primitives';
import { KernelFinding, KernelNextStep, KernelStage, KernelVerification } from '../Components/console/shared';

interface HariAbility { name: string; version: number; provides: string[]; borrows: Record<string, number>; needs: string[] }
interface HariSkill { id: string; versions: number[]; capabilities: string[]; describe: string }

interface KernelProps {
  connected: boolean;
  user: { login?: string } | null;
  reposCount: number;
  selectedRepo: string | null;
  openIssues: number;
  openPulls: number;
  provider: { label: string; model: string; keyReady: boolean };
  budget: { attempts: number | null; reservedMicros: number | null; canRequest: boolean } | null;
  hari: { frozen: boolean; abilities: HariAbility[]; skills: HariSkill[] } | null;
  autopilotCaps: { remaining: number; maxAttempts: number };
  worker: { isLive: boolean; status: string };
  autoStats: { total: number; completed: number; failed: number; skippedPrivate: number; skippedBudget: number; enabledRepositories: string[] };
  recentFindings: KernelFinding[];
  stages: KernelStage[];
  nextStep: KernelNextStep;
  lastVerification: KernelVerification | null;
  publishedPr: { repository: string; number: number; url: string } | null;
}

const STATE_MARK = { done: '[x]', active: '[>]', todo: '[ ]' } as const;

export default function Kernel(props: KernelProps) {
  const { connected, user, reposCount, selectedRepo, provider, budget, hari, worker, autoStats, recentFindings, stages, nextStep, lastVerification, publishedPr } = props;
  return (
    <AppLayout activeNav="kernel">
      <Head title="Console" />
      <div className="space-y-3">
        <div className="flex flex-wrap items-center gap-2 border border-[var(--border)] bg-[var(--accent-soft)] px-3 py-2 text-xs">
          <span className="font-semibold text-[var(--accent-text)]">next: {nextStep.title}</span>
          <span className="text-[var(--fg-muted)]">{nextStep.detail}</span>
          <Link href={nextStep.href} className="ml-auto inline-flex items-center gap-1 font-semibold text-[var(--accent-text)] hover:underline">
            {nextStep.cta} <ArrowRight className="h-3 w-3" />
          </Link>
        </div>

        <div className="grid gap-3 lg:grid-cols-3">
          <Panel title="connection">
            <Stat label="github" value={connected ? `connected @${user?.login ?? '?'}` : 'not connected'} tone={connected ? 'ok' : 'default'} />
            <Stat label="repositories" value={String(reposCount)} />
            <Stat label="selected" value={selectedRepo ?? 'none'} />
            <Stat label="open issues" value={String(props.openIssues)} />
            <Stat label="open pulls" value={String(props.openPulls)} />
          </Panel>

          <Panel title="pipeline">
            <ul className="space-y-0.5 text-xs">
              {stages.map((stage) => (
                <li key={stage.key} className="flex items-baseline gap-2">
                  <span className={stage.state === 'done' ? 'text-emerald-600 dark:text-emerald-400' : stage.state === 'active' ? 'text-[var(--accent-text)]' : 'text-[var(--fg-subtle)]'}>{STATE_MARK[stage.state]}</span>
                  <Link href={stage.href} className="font-medium hover:underline">{stage.title.toLowerCase()}</Link>
                  <span className="min-w-0 flex-1 truncate text-right text-[var(--fg-muted)]">{stage.detail}</span>
                </li>
              ))}
            </ul>
          </Panel>

          <Panel title="engine">
            <Stat label="provider" value={provider.label} />
            <Stat label="model" value={provider.model} />
            <Stat label="api key" value={provider.keyReady ? 'ready' : 'missing'} tone={provider.keyReady ? 'ok' : 'danger'} />
            <Stat label="usage" value={budget ? `${budget.attempts ?? 0} req · $${((budget.reservedMicros ?? 0) / 1_000_000).toFixed(2)} tracked` : 'n/a'} />
            <Stat label="autopilot" value={worker.isLive ? `live (${worker.status})` : worker.status} tone={worker.isLive ? 'ok' : 'default'} />
            <Stat label="auto-review" value={`${autoStats.completed} done · ${autoStats.failed} failed · ${autoStats.skippedPrivate} skipped (private)`} />
          </Panel>
        </div>

        <div className="grid gap-3 lg:grid-cols-2">
          <Panel title="hari">
            {hari ? (
              <details className="text-xs">
                <summary className="cursor-pointer text-[var(--fg-muted)] hover:text-[var(--fg)]">
                  {hari.abilities.length} {hari.abilities.length === 1 ? 'ability' : 'abilities'} · {hari.skills.length} {hari.skills.length === 1 ? 'skill' : 'skills'} <span className="sr-only">· {hari.frozen ? 'frozen' : 'mutable'}</span>
                </summary>
              <div className="mt-3 space-y-2">
                <ul className="space-y-0.5">
                  {hari.abilities.map((a) => (
                    <li key={a.name} className="flex flex-wrap gap-x-2">
                      <span className="font-semibold">{a.name}</span>
                      <span className="text-[var(--fg-muted)]">v{a.version}</span>
                      <span className="text-[var(--fg-muted)]">provides {a.provides.join(', ') || '—'}</span>
                      {Object.keys(a.borrows).length > 0 && <span className="text-[var(--fg-muted)]">borrows {Object.entries(a.borrows).map(([k, v]) => `${k}@${v}`).join(', ')}</span>}
                    </li>
                  ))}
                </ul>
                <ul className="space-y-0.5 border-t border-[var(--border)] pt-2">
                  {hari.skills.map((s) => (
                    <li key={s.id} className="flex flex-wrap gap-x-2">
                      <span className="font-medium">{s.id}</span>
                      <span className="text-[var(--fg-muted)]">v{s.versions.join(',')}</span>
                      <span className="min-w-0 flex-1 truncate text-[var(--fg-muted)]">{s.describe}</span>
                    </li>
                  ))}
                </ul>
              </div>
              </details>
            ) : (
              <p className="text-xs text-[var(--fg-muted)]">registry unavailable</p>
            )}
          </Panel>

          <Panel title="verification" right={publishedPr ? <span className="text-[11px] text-[var(--fg-muted)]">draft PR #{publishedPr.number}</span> : undefined}>
            {lastVerification ? (
              <div className="text-xs">
                <Stat label="last decision" value={lastVerification.decision} tone={lastVerification.decision === 'allow' ? 'ok' : 'danger'} />
                <Stat label="run" value={lastVerification.isSimulated ? 'simulated' : 'genuine'} />
                <Stat label="repository" value={lastVerification.repository ?? 'n/a'} />
                <Stat label="receipt" value={lastVerification.receiptSha256 ? `${lastVerification.receiptSha256.slice(0, 12)}…` : 'n/a'} />
                {publishedPr && (
                  <a href={publishedPr.url} target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 font-medium text-[var(--accent-text)] hover:underline">
                    {publishedPr.repository}#{publishedPr.number} <ExternalLink className="h-3 w-3" />
                  </a>
                )}
              </div>
            ) : (
              <p className="text-xs text-[var(--fg-muted)]">no verification yet — run one from an issue</p>
            )}
          </Panel>
        </div>

        <Panel title="recent findings" right={<Link href="/repositories" className="text-[11px] text-[var(--accent-text)] hover:underline">review queue →</Link>}>
          {recentFindings.length === 0 ? (
            <p className="text-xs text-[var(--fg-muted)]">no findings yet</p>
          ) : (
            <ul className="divide-y divide-[var(--border)] text-xs">
              {recentFindings.map((f) => (
                <li key={f.id} className="flex flex-wrap items-baseline gap-x-2 py-1">
                  <span className="font-medium">{f.repository}#{f.pull_number}</span>
                  <span className="text-[var(--fg-muted)]">review commit: {typeof f.head_sha === 'string' && f.head_sha ? f.head_sha.slice(0, 12) : 'unavailable'}</span>
                  <span className={f.status === 'completed' ? 'text-emerald-700 dark:text-emerald-400' : 'text-[var(--fg-muted)]'}>{f.status}</span>
                  {f.risk_level && <span className="text-[var(--fg-muted)]">risk: {f.risk_level}</span>}
                  <span className="min-w-0 flex-1 truncate text-[var(--fg-muted)]">{f.summary ?? f.recommendation ?? ''}</span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>
    </AppLayout>
  );
}
