import React from 'react';
import { GitHubRepo, SettingsProps } from './types';
import { formatDateTime } from '../ui/date';

interface Props {
  connected: boolean;
  repositories: GitHubRepo[];
  autoReview: SettingsProps['autoReview'];
  pendingRepo: string | null;
  error: string | null;
  onToggle: (repository: string, enabled: boolean) => void;
  onRefresh: () => void;
}

function statusLabel(status: string): string {
  switch (status) {
    case 'completed':
      return 'Reviewed';
    case 'failed':
      return 'Failed';
    case 'skipped_private':
      return 'Skipped (private)';
    case 'skipped_budget':
      return 'Skipped (budget)';
    case 'skipped_opted_out':
      return 'Skipped (opted out)';
    case 'in_progress':
      return 'In progress';
    case 'unknown':
      return 'Unknown';
    default:
      return status.replace(/_/g, ' ');
  }
}

export function AutoReviewPanel({ connected, repositories, autoReview, pendingRepo, error, onToggle, onRefresh }: Props) {
  const settings = autoReview?.settings || {};
  const stats = autoReview?.stats;
  const recentResults = autoReview?.recentResults || [];

  return (
    <section className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs" aria-labelledby="auto-review-title">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="auto-review-title" className="text-sm font-semibold text-slate-900 dark:text-slate-100">Autopilot reviews</h2>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">Muse reviews open and updated public pull requests while autopilot is running.</p>
        </div>
        {autoReview && (
          <button type="button" onClick={onRefresh} className="text-xs text-accent-700 dark:text-accent-300 hover:underline" aria-label="Refresh autopilot status">
            {autoReview.worker.isLive ? 'Autopilot running' : 'Autopilot offline'} · Refresh
          </button>
        )}
      </div>

      {!autoReview ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">Automatic actions are not active yet. Open a pull request to review it manually.</p>
      ) : !connected ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">Connect GitHub to choose repositories.</p>
      ) : repositories.length === 0 ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">No repositories are available to this GitHub connection.</p>
      ) : (
        <div className="divide-y divide-slate-100 dark:divide-slate-800 border border-slate-200 dark:border-slate-800 rounded-lg">
          {repositories.map((repo) => {
            const saved = Object.values(settings).find((item) => item.repository.toLowerCase() === repo.fullName.toLowerCase());
            const enabled = !repo.isPrivate && saved?.enabled === true;
            return (
              <div key={repo.fullName} className="flex items-center justify-between gap-3 px-3 py-3 text-xs">
                <div className="min-w-0">
                  <div className="break-all font-medium text-slate-900 dark:text-slate-100">{repo.fullName}</div>
                  {repo.isPrivate ? (
                    <div className="mt-1 text-slate-500 dark:text-slate-400">
                      Private repositories are not sent to Muse. Local issue examination, offline verification, and user-permitted PR actions remain available.
                    </div>
                  ) : (
                    <div className="mt-1 text-slate-500 dark:text-slate-400">
                      Public repository · Automatic Muse PR review {enabled ? 'enabled' : 'disabled'}.
                    </div>
                  )}
                </div>
                <label className="flex shrink-0 items-center gap-2 text-slate-700 dark:text-slate-300">
                  <span>{repo.isPrivate ? 'Off (Private)' : enabled ? 'On' : 'Off'}</span>
                  <input type="checkbox" aria-label={`Automatic review for ${repo.fullName}`} checked={enabled}
                    disabled={pendingRepo !== null || repo.isPrivate}
                    onChange={(event) => onToggle(repo.fullName, event.currentTarget.checked)}
                    className="accent-600 disabled:opacity-50" />
                </label>
              </div>
            );
          })}
        </div>
      )}

      {autoReview && (
        <div className="space-y-2 text-xs text-slate-500 dark:text-slate-400">
          <p>Reviews use credits and appear in ProofPatcher. Automatic comments require a separate opt-in below.</p>
          {!autoReview.worker.isLive && <p>Start autopilot: <code className="select-all">php artisan review:poll --daemon</code></p>}
        </div>
      )}

      {autoReview && connected && stats && (
        <div className="text-xs text-slate-500 dark:text-slate-400" aria-label="Automatic review totals">
          {stats.completed} reviewed · {stats.failed} failed · {stats.skippedPrivate} skipped (private) · {stats.skippedBudget} skipped (budget)
        </div>
      )}

      {autoReview && connected && (
        <div className="space-y-2">
          <h3 className="text-xs font-semibold text-slate-900 dark:text-slate-100">Recent findings</h3>
          {recentResults.length === 0 ? (
            <p className="text-xs text-slate-500 dark:text-slate-400">
              No automatic reviews yet. Enable a public repository above and start autopilot; new findings will appear here and stay in ProofPatcher.
            </p>
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800 border border-slate-200 dark:border-slate-800 rounded-lg">
              {recentResults.map((result) => (
                <li key={result.id} className="px-3 py-2.5 text-xs space-y-1">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-medium text-slate-900 dark:text-slate-100 break-all">
                      {result.repository}#{result.pull_number}
                    </span>
                    <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold border ${
                      result.status === 'completed'
                        ? 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-800'
                        : result.status === 'failed'
                        ? 'bg-rose-50 text-rose-800 border-rose-200 dark:bg-rose-950/50 dark:text-rose-300 dark:border-rose-800'
                        : 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700'
                    }`}>
                      {statusLabel(result.status)}
                    </span>
                  </div>
                  <div className="text-slate-500 dark:text-slate-400">
                    Review commit: {typeof result.head_sha === 'string' && result.head_sha ? result.head_sha.slice(0, 12) : 'unavailable'}
                  </div>
                  {result.status === 'completed' && (
                    <div className="text-slate-600 dark:text-slate-300 space-y-0.5">
                      <div>
                        {result.recommendation ? result.recommendation.replace(/_/g, ' ') : 'No recommendation'}
                        {result.risk_level ? ` · ${result.risk_level} risk` : ''}
                      </div>
                      {result.summary && <p className="text-slate-500 dark:text-slate-400 line-clamp-2">{result.summary}</p>}
                    </div>
                  )}
                  <div className="text-[11px] text-slate-400 dark:text-slate-500">
                    {result.published_status === 'one_inline_comment' ? 'One suggestion posted to GitHub' : result.published_status === 'review_summary' ? 'Review summary posted to GitHub' : result.published_status === 'publication_unknown' ? 'Comment outcome unknown · Check GitHub' : 'Stored locally · Not posted to GitHub'}
                    {result.reviewed_at ? ` · ${formatDateTime(result.reviewed_at)}` : ''}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {error && <p role="alert" className="text-xs text-rose-700 dark:text-rose-300">{error}</p>}
    </section>
  );
}
