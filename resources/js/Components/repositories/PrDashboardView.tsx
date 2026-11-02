import React, { useState, useMemo } from 'react';
import {
  FolderGit2,
  GitPullRequest,
  Search,
  Check,
  Lock,
  ArrowRight,
  ExternalLink,
  ChevronDown,
  RefreshCw,
  SlidersHorizontal,
} from 'lucide-react';
import { GitHubConnectionData, GitHubPull } from './types';
import { PrInspectForm } from './PrInspectForm';
import { StateChip } from '../console/primitives';

const PR_PAGE_SIZE = 8;

interface PrDashboardViewProps {
  githubConnection: GitHubConnectionData;
  handleSelectRepository: (repoFullName: string) => void;
  handleCaptureGitHubPull: (pullItem: GitHubPull) => void;
  inspectUrl: string;
  setInspectUrl: (val: string) => void;
  handleInspectSubmit: (e: React.FormEvent) => void;
  inspectProcessing: boolean;
}

export function PrDashboardView({
  githubConnection,
  handleSelectRepository,
  handleCaptureGitHubPull,
  inspectUrl,
  setInspectUrl,
  handleInspectSubmit,
  inspectProcessing,
}: PrDashboardViewProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'open' | 'merged' | 'closed'>('all');
  const [manualInspectOpen, setManualInspectOpen] = useState(false);
  const [prPage, setPrPage] = useState(0);

  const repositories = githubConnection.repositories || [];
  const selectedRepo = githubConnection.selectedRepo || repositories[0]?.fullName || null;
  const pulls = githubConnection.repoPulls || [];

  const filteredPulls = useMemo(() => {
    return pulls.filter((pr) => {
      const state = ((pr as any).state || 'open').toLowerCase();
      // Status filter
      if (statusFilter === 'open' && state !== 'open') return false;
      if (statusFilter === 'merged' && state !== 'merged') return false;
      if (statusFilter === 'closed' && state !== 'closed') return false;

      // Query filter
      if (!searchQuery.trim()) return true;
      const q = searchQuery.toLowerCase().trim();
      return (
        pr.title.toLowerCase().includes(q) ||
        `#${pr.number}`.includes(q) ||
        pr.user?.toLowerCase().includes(q) ||
        pr.head?.toLowerCase().includes(q)
      );
    });
  }, [pulls, statusFilter, searchQuery]);

  return (
    <div className="space-y-6">
      {/* Top Repository Bar & Stats */}
      <div className="border border-[var(--border)] bg-[var(--bg-raised)] p-4 space-y-4">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <span className="p-1 rounded bg-accent-50 text-accent-700 dark:bg-accent-950/40 dark:text-accent-300">
                <GitPullRequest className="w-4 h-4" />
              </span>
              <h1 className="text-lg font-bold text-slate-900 tracking-tight dark:text-slate-100">Pull Request Reviews</h1>
            </div>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Select a pull request to inspect its changes and checks.
            </p>
          </div>

          {/* Repository Selector Dropdown */}
          <div className="flex items-center gap-2.5">
            <div className="relative">
              <select
                value={selectedRepo || ''}
                onChange={(e) => handleSelectRepository(e.target.value)}
                className="appearance-none pl-8 pr-8 py-1.5 text-xs font-semibold rounded-lg border border-slate-300 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-accent-600 shadow-2xs transition cursor-pointer text-slate-800 dark:bg-[#141821] dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
              >
                {repositories.map((repo) => (
                  <option key={repo.fullName} value={repo.fullName}>
                    {repo.fullName} {repo.isPrivate ? '(Private)' : ''}
                  </option>
                ))}
              </select>
              <FolderGit2 className="w-3.5 h-3.5 text-slate-500 absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none dark:text-slate-400" />
              <ChevronDown className="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            </div>

            <button
              type="button"
              onClick={() => selectedRepo && handleSelectRepository(selectedRepo)}
              title="Refresh repository pull requests"
              className="p-1.5 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-600 shadow-2xs transition dark:bg-[#141821] dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
            >
              <RefreshCw className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>

        {/* Filter & Search Controls */}
        <div className="pt-2 flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-t border-slate-100 dark:border-slate-800">
          <div className="flex flex-wrap items-center gap-1.5">
            <button
              type="button"
              onClick={() => { setStatusFilter('all'); setPrPage(0); }}
              className={`px-3 py-1 text-xs font-medium rounded-lg transition ${
                statusFilter === 'all'
                  ? 'bg-accent-700 text-white font-semibold shadow-xs'
                  : 'text-slate-600 hover:text-slate-900 bg-slate-100/70 hover:bg-slate-200/70 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:text-slate-100'
              }`}
            >
              All ({pulls.length})
            </button>
            <button
              type="button"
              onClick={() => { setStatusFilter('open'); setPrPage(0); }}
              className={`px-3 py-1 text-xs font-medium rounded-lg transition ${
                statusFilter === 'open'
                  ? 'bg-accent-700 text-white font-semibold shadow-xs'
                  : 'text-slate-600 hover:text-slate-900 bg-slate-100/70 hover:bg-slate-200/70 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:text-slate-100'
              }`}
            >
              Open ({pulls.filter((p) => ((p as any).state || 'open').toLowerCase() === 'open').length})
            </button>
            <button
              type="button"
              onClick={() => { setStatusFilter('merged'); setPrPage(0); }}
              className={`px-3 py-1 text-xs font-medium rounded-lg transition ${
                statusFilter === 'merged'
                  ? 'bg-accent-700 text-white font-semibold shadow-xs'
                  : 'text-slate-600 hover:text-slate-900 bg-slate-100/70 hover:bg-slate-200/70 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:text-slate-100'
              }`}
            >
              Merged ({pulls.filter((p) => ((p as any).state || '').toLowerCase() === 'merged').length})
            </button>
            <button
              type="button"
              onClick={() => { setStatusFilter('closed'); setPrPage(0); }}
              className={`px-3 py-1 text-xs font-medium rounded-lg transition ${
                statusFilter === 'closed'
                  ? 'bg-accent-700 text-white font-semibold shadow-xs'
                  : 'text-slate-600 hover:text-slate-900 bg-slate-100/70 hover:bg-slate-200/70 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:text-slate-100'
              }`}
            >
              Closed ({pulls.filter((p) => ((p as any).state || '').toLowerCase() === 'closed').length})
            </button>
          </div>

          <div className="relative w-full sm:w-72">
            <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Search pull requests..."
              value={searchQuery}
              onChange={(e) => { setSearchQuery(e.target.value); setPrPage(0); }}
              className="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-accent-600 shadow-2xs dark:border-slate-700"
            />
          </div>
        </div>
      </div>

      {/* Pull Requests Stream List */}
      {(() => {
        const prPageCount = Math.max(1, Math.ceil(filteredPulls.length / PR_PAGE_SIZE));
        const page = Math.min(prPage, prPageCount - 1);
        const pagePulls = filteredPulls.slice(page * PR_PAGE_SIZE, page * PR_PAGE_SIZE + PR_PAGE_SIZE);
        return (
      <div className="space-y-2">
        {filteredPulls.length === 0 ? (
          <div className="border border-[var(--border)] bg-[var(--bg-raised)] p-8 text-center space-y-3">
            <div className="w-10 h-10 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center mx-auto dark:bg-slate-800 dark:text-slate-400">
              <GitPullRequest className="w-5 h-5" />
            </div>
            <div className="space-y-1 max-w-sm mx-auto">
              <h3 className="text-sm font-semibold">No pull requests found</h3>
              <p className="text-xs text-[var(--fg-muted)]">
                {searchQuery
                  ? `No pull requests match "${searchQuery}".`
                  : `No pull requests are currently open in ${selectedRepo || 'this repository'}.`}
              </p>
            </div>
          </div>
        ) : (
          <>
          <div className="divide-y divide-[var(--border)] border border-[var(--border)] bg-[var(--bg-raised)]">
          {pagePulls.map((pr) => {
            const state = ((pr as any).state || 'open').toLowerCase();
            const isDraft = Boolean((pr as any).isDraft) && state === 'open';
            return (
              <div key={pr.number} className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0 space-y-1">
                  <div className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="font-semibold font-mono">#{pr.number}</span>
                    <StateChip state={isDraft ? 'draft' : state} />
                    {pr.isPrivate && <StateChip state="private" />}
                    <span className="text-[var(--fg-muted)]">by @{pr.user}</span>
                  </div>
                  <h2 className="truncate text-sm font-semibold">{pr.title}</h2>
                  <div className="flex flex-wrap items-center gap-2 font-mono text-[11px] text-[var(--fg-muted)]">
                    <span>{pr.head}</span><span>→</span><span>{pr.base}</span>
                    <span>·</span><span>commit {pr.headSha.slice(0, 8)}</span>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => handleCaptureGitHubPull(pr)}
                  className="inline-flex shrink-0 items-center gap-1.5 self-start border border-[var(--border-strong)] px-3 py-1.5 text-xs font-semibold sm:self-center"
                >
                  <span>Inspect &amp; Review</span>
                  <ArrowRight className="w-3.5 h-3.5" />
                </button>
              </div>
            );
          })}
          </div>
          <div className="flex items-center justify-between text-[11px] text-[var(--fg-muted)]">
            <span>{filteredPulls.length} pull requests · page {page + 1} of {prPageCount}</span>
            <span className="flex gap-2">
              <button type="button" disabled={page === 0} onClick={() => setPrPage(page - 1)} className="font-semibold disabled:opacity-40">← prev</button>
              <button type="button" disabled={page >= prPageCount - 1} onClick={() => setPrPage(page + 1)} className="font-semibold disabled:opacity-40">next →</button>
            </span>
          </div>
          </>
        )}
      </div>
        );
      })()}

      {/* Discreet Accordion for Manual URL Input */}
      <div className="pt-2">
        <button
          type="button"
          onClick={() => setManualInspectOpen(!manualInspectOpen)}
          className="text-xs text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition dark:text-slate-400"
        >
          <SlidersHorizontal className="w-3.5 h-3.5 text-slate-400" />
          <span>Need to inspect an external PR or custom URL?</span>
          <ChevronDown
            className={`w-3.5 h-3.5 text-slate-400 transition-transform ${
              manualInspectOpen ? 'rotate-180' : ''
            }`}
          />
        </button>

        {manualInspectOpen && (
          <div className="mt-3">
            <PrInspectForm
              url={inspectUrl}
              setUrl={setInspectUrl}
              onSubmit={handleInspectSubmit}
              processing={inspectProcessing}
              isHero={false}
            />
          </div>
        )}
      </div>
    </div>
  );
}
