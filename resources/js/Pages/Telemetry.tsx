import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';

export interface TelemetryData {
  requests: { total: number; received: number; uncertain: number; reserved: number; reservedMicros: number };
  tokens: { prompt: number; completion: number; total: number };
  perDay: Array<{ day: string; requests: number; micros: number }>;
  models: Array<{ model: string; reviews: number; promptTokens: number; completionTokens: number }>;
  reviews: { total: number; completed: number; failed: number; skippedPrivate: number; skippedBudget: number };
  feed: Array<{ at: string; kind: string; status: string; detail: string; micros: number | null; tokens: number | null }>;
  generatedAt: string;
  ledgerFound: boolean;
}

function dollars(micros: number): string {
  return `$${(micros / 1000000).toFixed(4)}`;
}

export default function Telemetry({ telemetry }: { telemetry: TelemetryData }) {
  const maxDay = Math.max(1, ...telemetry.perDay.map((d) => d.requests));

  return (
    <AppLayout activeNav="telemetry">
      <Head title="Telemetry · ProofPatcher" />

      <div className="space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">
              ProofPatcher Kernel
            </p>
            <h1 className="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">
              Telemetry
            </h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
              Every model request and review outcome, measured locally. Reservations are pre-call
              estimates, not provider bills.
            </p>
          </div>
          <span className="text-[11px] font-mono text-slate-400 dark:text-slate-500">
            {new Date(telemetry.generatedAt).toLocaleString()}
          </span>
        </div>

        {!telemetry.ledgerFound && (
          <p role="note" className="rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] p-3.5 text-xs text-slate-500 dark:text-slate-400">
            No request ledger found yet. Request counts start here after the first model call.
          </p>
        )}

        <section aria-label="Totals" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Model requests', value: String(telemetry.requests.total), sub: `${telemetry.requests.received} received · ${telemetry.requests.uncertain} uncertain` },
            { label: 'Estimated reserved', value: dollars(telemetry.requests.reservedMicros), sub: 'pre-call estimates' },
            { label: 'Tokens in / out', value: `${telemetry.tokens.prompt} / ${telemetry.tokens.completion}`, sub: `${telemetry.tokens.total} total` },
            { label: 'Reviews completed', value: `${telemetry.reviews.completed} of ${telemetry.reviews.total}`, sub: `${telemetry.reviews.failed} failed · ${telemetry.reviews.skippedPrivate} skipped (private)` },
          ].map((stat) => (
            <div key={stat.label} className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] p-4 shadow-xs">
              <div className="text-[11px] font-medium text-slate-500 dark:text-slate-400">{stat.label}</div>
              <div className="mt-1 font-mono text-xl font-semibold text-slate-900 dark:text-slate-100">{stat.value}</div>
              <div className="mt-0.5 text-[11px] text-slate-400 dark:text-slate-500">{stat.sub}</div>
            </div>
          ))}
        </section>

        <section className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] p-5 shadow-xs">
            <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Models</h2>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">From stored completed reviews.</p>
            {telemetry.models.length === 0 ? (
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">No model usage recorded yet.</p>
            ) : (
              <table className="mt-3 w-full text-xs">
                <thead>
                  <tr className="text-left text-[11px] text-slate-400 dark:text-slate-500">
                    <th className="pb-2 pr-3 font-medium">Model</th>
                    <th className="pb-2 pr-3 font-medium text-right">Reviews</th>
                    <th className="pb-2 pr-3 font-medium text-right">In</th>
                    <th className="pb-2 font-medium text-right">Out</th>
                  </tr>
                </thead>
                <tbody className="font-mono text-[11px]">
                  {telemetry.models.map((model) => (
                    <tr key={model.model} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="py-2 pr-3 text-slate-800 dark:text-slate-200 break-all">{model.model}</td>
                      <td className="py-2 pr-3 text-right text-slate-600 dark:text-slate-300">{model.reviews}</td>
                      <td className="py-2 pr-3 text-right text-slate-600 dark:text-slate-300">{model.promptTokens}</td>
                      <td className="py-2 text-right text-slate-600 dark:text-slate-300">{model.completionTokens}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] p-5 shadow-xs">
            <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Daily volume</h2>
            <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Model requests per day, last 14 days.</p>
            {telemetry.perDay.length === 0 ? (
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">No daily activity yet.</p>
            ) : (
              <ol className="mt-3 space-y-1.5">
                {telemetry.perDay.map((day) => (
                  <li key={day.day} className="flex items-center gap-2 text-[11px] font-mono">
                    <span className="w-[76px] shrink-0 text-slate-500 dark:text-slate-400">{day.day.slice(5)}</span>
                    <span className="h-3 flex-1 overflow-hidden rounded bg-slate-100 dark:bg-slate-800">
                      <span
                        className="block h-full rounded bg-accent-600 dark:bg-accent-400"
                        style={{ width: `${Math.max(2, Math.round((day.requests / maxDay) * 100))}%` }}
                      />
                    </span>
                    <span className="w-28 shrink-0 whitespace-nowrap text-right text-slate-600 dark:text-slate-300">
                      {day.requests} · {dollars(day.micros)}
                    </span>
                  </li>
                ))}
              </ol>
            )}
          </div>
        </section>

        <section className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] p-5 shadow-xs">
          <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Request feed</h2>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Latest 25 model requests and review outcomes.</p>
          {telemetry.feed.length === 0 ? (
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Nothing recorded yet.</p>
          ) : (
            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[640px] text-xs">
                <thead>
                  <tr className="text-left text-[11px] text-slate-400 dark:text-slate-500">
                    <th className="pb-2 pr-3 font-medium">Time</th>
                    <th className="pb-2 pr-3 font-medium">Kind</th>
                    <th className="pb-2 pr-3 font-medium">Status</th>
                    <th className="pb-2 pr-3 font-medium">Detail</th>
                    <th className="pb-2 font-medium text-right">Usage</th>
                  </tr>
                </thead>
                <tbody>
                  {telemetry.feed.map((event, index) => (
                    <tr key={`${event.at}-${index}`} className="border-t border-slate-100 dark:border-slate-800">
                      <td className="py-2 pr-3 font-mono text-[11px] text-slate-500 dark:text-slate-400 whitespace-nowrap">
                        {(event.at || '').slice(0, 19).replace('T', ' ')}
                      </td>
                      <td className="py-2 pr-3 text-slate-600 dark:text-slate-300">{event.kind === 'review' ? 'review' : 'request'}</td>
                      <td className="py-2 pr-3 text-slate-600 dark:text-slate-300">{event.status.replace(/_/g, ' ')}</td>
                      <td className="py-2 pr-3 text-slate-700 dark:text-slate-200 max-w-[320px] truncate">{event.detail}</td>
                      <td className="py-2 font-mono text-[11px] text-right text-slate-500 dark:text-slate-400 whitespace-nowrap">
                        {event.tokens !== null ? `${event.tokens} tok` : '—'}
                        {event.micros !== null ? ` · ${dollars(event.micros)}` : ''}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <p className="text-[11px] text-slate-400 dark:text-slate-500">
          Same numbers in the terminal: <code className="select-all font-mono">php artisan kernel:telemetry</code>.
          {' '}<Link href="/settings" className="text-accent-700 dark:text-accent-300 hover:underline">Usage &amp; Limits</Link> holds the budget controls.
        </p>
      </div>
    </AppLayout>
  );
}
