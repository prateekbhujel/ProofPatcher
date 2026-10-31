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
      <div className="bg-white rounded-xl border border-slate-200/90 shadow-xs p-5 sm:p-6 space-y-4 dark:bg-[#141821] dark:border-slate-800/90">
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
              onClick={() => setStatusFilter('all')}
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
              onClick={() => setStatusFilter('open')}
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
              onClick={() => setStatusFilter('merged')}
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
              onClick={() => setStatusFilter('closed')}
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
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-accent-600 shadow-2xs dark:border-slate-700"
            />
          </div>
        </div>
      </div>

      {/* Pull Requests Stream List */}
      <div className="space-y-3">
        {filteredPulls.length === 0 ? (
          <div className="bg-white rounded-xl border border-slate-200 p-10 text-center space-y-3 shadow-xs dark:bg-[#141821] dark:border-slate-800">
            <div className="w-10 h-10 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center mx-auto dark:bg-slate-800 dark:text-slate-400">
              <GitPullRequest className="w-5 h-5" />
            </div>
            <div className="space-y-1 max-w-sm mx-auto">
              <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-100">No pull requests found</h3>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {searchQuery
                  ? `No pull requests match "${searchQuery}".`
                  : `No pull requests are currently open in ${selectedRepo || 'this repository'}.`}
              </p>
            </div>
          </div>
        ) : (
          filteredPulls.map((pr) => {
            const state = ((pr as any).state || 'open').toLowerCase();
            const isMerged = state === 'merged';
            const isClosed = state === 'closed';
            const isDraft = Boolean((pr as any).isDraft) && state === 'open';
            return (
              <div
                key={pr.number}
                className="bg-white rounded-xl border border-slate-200/90 hover:border-accent-300 hover:shadow-xs p-4 sm:p-5 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4 dark:bg-[#141821] dark:border-slate-800/90"
              >
                <div className="space-y-1.5 min-w-0">
                  <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span className="font-semibold text-slate-900 font-mono dark:text-slate-100">#{pr.number}</span>
                    <span
                      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold ${
                        isMerged
                          ? 'bg-accent-50 text-accent-700 border border-accent-200 dark:bg-accent-950/40 dark:border-accent-800 dark:text-accent-300'
                          : isClosed
                          ? 'bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-400'
                          : isDraft
                          ? 'bg-slate-100 text-slate-700 border border-slate-300 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-300'
                          : 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400'
                      }`}
                    >
                      {isMerged && <Check className="w-2.5 h-2.5" />}
                      <span>{isMerged ? 'MERGED' : isClosed ? 'CLOSED' : isDraft ? 'DRAFT' : 'OPEN'}</span>
                    </span>
                    {pr.isPrivate && (
                      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] bg-amber-50 text-amber-800 border border-amber-200 font-medium dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-300">
                        <Lock className="w-2.5 h-2.5" />
                        <span>Private</span>
                      </span>
                    )}
                    <span>·</span>
                    <span>by @{pr.user}</span>
                  </div>

                  <h2 className="text-sm font-semibold text-slate-900 truncate dark:text-slate-100">{pr.title}</h2>

                  <div className="flex flex-wrap items-center gap-2 text-xs font-mono text-slate-500 text-[11px] dark:text-slate-400">
                    <span className="bg-slate-100 text-slate-700 px-1.5 py-0.2 rounded border border-slate-200 dark:bg-slate-800 dark:border-slate-800 dark:text-slate-300">
                      {pr.head}
                    </span>
                    <span>→</span>
                    <span className="bg-slate-100 text-slate-700 px-1.5 py-0.2 rounded border border-slate-200 dark:bg-slate-800 dark:border-slate-800 dark:text-slate-300">
                      {pr.base}
                    </span>
                    <span>·</span>
                    <span className="text-slate-400">Commit {pr.headSha.slice(0, 8)}</span>
                  </div>
                </div>

                <div className="flex items-center gap-2.5 shrink-0 self-start sm:self-center">
                  <button
                    type="button"
                    onClick={() => handleCaptureGitHubPull(pr)}
                    className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold rounded-lg text-white bg-accent-700 hover:bg-accent-800 shadow-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600"
                  >
                    <span>Inspect &amp; Review</span>
                    <ArrowRight className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>

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
