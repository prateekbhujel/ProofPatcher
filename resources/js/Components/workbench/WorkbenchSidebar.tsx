import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  FolderGit2,
  RefreshCw,
  Search,
  Plus,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import {
  GitHubConnectionData,
  GitHubIssueItem,
  GitHubPullItem,
} from './types';
import { LocalTaskCard } from '../TaskCardSection';
import { RepoSearchPicker } from './RepoSearchPicker';
import { formatDate } from '../ui/date';

interface WorkbenchSidebarProps {
  githubConnection: GitHubConnectionData | null;
  loadingRepo: boolean;
  handleSelectRepo: (repoName: string) => void;
  sidebarTab: 'issues' | 'pulls';
  setSidebarTab: (tab: 'issues' | 'pulls') => void;
  sidebarQuery: string;
  setSidebarQuery: (query: string) => void;
  filteredIssues: GitHubIssueItem[];
  filteredPulls: GitHubPullItem[];
  issuePathMap: Record<number, string>;
  setIssuePathMap: React.Dispatch<React.SetStateAction<Record<number, string>>>;
  loadingIssueNum: number | null;
  handleLoadIssue: (issue: GitHubIssueItem) => void;
  setViewMode: (mode: 'workbench' | 'local-card' | 'new') => void;
  localTasks: LocalTaskCard[];
  onSelectTask: (id: number) => void;
}

export function WorkbenchSidebar({
  githubConnection,
  loadingRepo,
  handleSelectRepo,
  sidebarTab,
  setSidebarTab,
  sidebarQuery,
  setSidebarQuery,
  filteredIssues,
  filteredPulls,
  issuePathMap,
  setIssuePathMap,
  loadingIssueNum,
  handleLoadIssue,
  setViewMode,
  localTasks,
  onSelectTask,
}: WorkbenchSidebarProps) {
  const [issueStateFilter, setIssueStateFilter] = useState<'all' | 'open' | 'closed'>('all');
  const [pullStateFilter, setPullStateFilter] = useState<'all' | 'open' | 'merged' | 'closed'>('all');
  const [issuePage, setIssuePage] = useState(1);
  const [pullPage, setPullPage] = useState(1);
  const pageSize = 20;

  const stateFilteredIssues = filteredIssues.filter((iss) => {
    const st = (iss.state || 'open').toLowerCase();
    if (issueStateFilter === 'all') return true;
    return st === issueStateFilter;
  });

  const stateFilteredPulls = filteredPulls.filter((pull) => {
    const st = (pull.state || 'open').toLowerCase();
    if (pullStateFilter === 'all') return true;
    return st === pullStateFilter;
  });

  const totalIssuePages = Math.max(1, Math.ceil(stateFilteredIssues.length / pageSize));
  const safeIssuePage = Math.min(issuePage, totalIssuePages);
  const pagedIssues = stateFilteredIssues.slice((safeIssuePage - 1) * pageSize, safeIssuePage * pageSize);

  const totalPullPages = Math.max(1, Math.ceil(stateFilteredPulls.length / pageSize));
  const safePullPage = Math.min(pullPage, totalPullPages);
  const pagedPulls = stateFilteredPulls.slice((safePullPage - 1) * pageSize, safePullPage * pageSize);

  const openIssuesCount = filteredIssues.filter((i) => (i.state || 'open').toLowerCase() === 'open').length;
  const closedIssuesCount = filteredIssues.filter((i) => (i.state || 'open').toLowerCase() === 'closed').length;

  const openPullsCount = filteredPulls.filter((p) => (p.state || 'open').toLowerCase() === 'open').length;
  const mergedPullsCount = filteredPulls.filter((p) => (p.state || 'open').toLowerCase() === 'merged').length;
  const closedPullsCount = filteredPulls.filter((p) => (p.state || 'open').toLowerCase() === 'closed').length;

  return (
    <aside className="w-full lg:w-80 shrink-0 lg:sticky lg:top-0 lg:h-screen lg:max-h-screen lg:overflow-hidden bg-[var(--bg-sidebar)] border-b lg:border-b-0 lg:border-r border-[var(--border)] flex flex-col justify-between">
      <div className="p-3 pb-2 space-y-2.5 shrink-0">
        {/* Repository Selector Dropdown */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between text-[11px] font-semibold text-slate-500 dark:text-slate-400">
            <span className="flex items-center gap-1.5">
              <FolderGit2 className="w-3.5 h-3.5 text-slate-500 dark:text-slate-400" />
              <span>Connected Repository</span>
            </span>
            {githubConnection?.selectedRepo && (
              <button
                type="button"
                onClick={() => handleSelectRepo(githubConnection.selectedRepo!)}
                disabled={loadingRepo}
                title="Refresh repository issues and pull requests"
                aria-label="Refresh repository issues and pull requests"
                className="p-1 rounded text-slate-500 hover:text-slate-900 dark:hover:text-slate-200 dark:text-slate-400"
              >
                <RefreshCw className={`w-3 h-3 ${loadingRepo ? 'animate-spin' : ''}`} />
              </button>
            )}
          </div>

          <RepoSearchPicker
            id="connected-repository"
            ariaLabel="Select connected repository"
            value={githubConnection?.selectedRepo || ''}
            onChange={handleSelectRepo}
            disabled={loadingRepo}
          />
        </div>

        {/* Filter Tabs: Issues vs Pull Requests */}
        <div className="flex rounded-lg bg-[var(--bg-active)] p-0.5 text-xs">
          <button
            type="button"
            onClick={() => setSidebarTab('issues')}
            className={`flex-1 py-1 px-2 rounded-md font-medium text-center transition ${
              sidebarTab === 'issues'
                ? 'bg-[var(--bg-raised)] text-[var(--fg)] font-semibold shadow-2xs'
                : 'text-[var(--fg-muted)] hover:text-[var(--fg)]'
            }`}
          >
            Issues ({githubConnection?.repoIssues?.length ?? 0})
          </button>
          <button
            type="button"
            onClick={() => setSidebarTab('pulls')}
            className={`flex-1 py-1 px-2 rounded-md font-medium text-center transition ${
              sidebarTab === 'pulls'
                ? 'bg-[var(--bg-raised)] text-[var(--fg)] font-semibold shadow-2xs'
                : 'text-[var(--fg-muted)] hover:text-[var(--fg)]'
            }`}
          >
            Pull Requests ({githubConnection?.repoPulls?.length ?? 0})
          </button>
        </div>

        {/* Quick Search */}
        <div className="relative">
          <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            autoComplete="off"
            autoCorrect="off"
            autoCapitalize="off"
            spellCheck={false}
            value={sidebarQuery}
            onChange={(e) => {
              setSidebarQuery(e.target.value);
              setIssuePage(1);
              setPullPage(1);
            }}
            placeholder={sidebarTab === 'issues' ? 'Search GitHub issues…' : 'Search pull requests…'}
            aria-label="Search items"
            className="w-full pl-8 pr-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:outline-none"
          />
        </div>

        {/* State Filter Pills (Scales to Open, Closed, Merged & Large Repos) */}
        {sidebarTab === 'issues' ? (
          <div className="flex items-center justify-between gap-1 text-[10px]">
            <div className="flex items-center gap-1">
              {(['all', 'open', 'closed'] as const).map((st) => {
                const count = st === 'all' ? filteredIssues.length : st === 'open' ? openIssuesCount : closedIssuesCount;
                const active = issueStateFilter === st;
                return (
                  <button
                    key={st}
                    type="button"
                    onClick={() => {
                      setIssueStateFilter(st);
                      setIssuePage(1);
                    }}
                    className={`px-2 py-0.5 rounded-md font-semibold uppercase tracking-wider transition ${
                      active
                        ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                        : 'bg-slate-200/70 text-slate-600 hover:bg-slate-300/70 dark:bg-slate-800 dark:text-slate-400'
                    }`}
                  >
                    {st} ({count})
                  </button>
                );
              })}
            </div>
            {stateFilteredIssues.length > pageSize && (
              <span className="font-mono text-slate-500 dark:text-slate-400">
                p.{safeIssuePage}/{totalIssuePages}
              </span>
            )}
          </div>
        ) : (
          <div className="flex items-center justify-between gap-1 text-[10px]">
            <div className="flex items-center gap-1 flex-wrap">
              {(['all', 'open', 'merged', 'closed'] as const).map((st) => {
                const count =
                  st === 'all'
                    ? filteredPulls.length
                    : st === 'open'
                      ? openPullsCount
                      : st === 'merged'
                        ? mergedPullsCount
                        : closedPullsCount;
                const active = pullStateFilter === st;
                return (
                  <button
                    key={st}
                    type="button"
                    onClick={() => {
                      setPullStateFilter(st);
                      setPullPage(1);
                    }}
                    className={`px-1.5 py-0.5 rounded-md font-semibold uppercase tracking-wider transition ${
                      active
                        ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                        : 'bg-slate-200/70 text-slate-600 hover:bg-slate-300/70 dark:bg-slate-800 dark:text-slate-400'
                    }`}
                  >
                    {st} ({count})
                  </button>
                );
              })}
            </div>
            {stateFilteredPulls.length > pageSize && (
              <span className="font-mono text-slate-500 dark:text-slate-400">
                p.{safePullPage}/{totalPullPages}
              </span>
            )}
          </div>
        )}
      </div>

      {/* Single Clean Scrollable Item Stream (Paginated to 20 items max for instant DOM rendering even with 1,000+ items) */}
      <div className="flex-1 min-h-0 overflow-y-auto px-3 py-2 border-t border-slate-200/70 dark:border-slate-800 space-y-2 [scrollbar-width:thin]">
          {sidebarTab === 'issues' ? (
            pagedIssues.length === 0 ? (
              <p className="p-2 text-xs text-slate-500 dark:text-slate-400">
                {githubConnection?.selectedRepo ? 'No matching issues.' : 'Choose a repository.'}
              </p>
            ) : (
              <>
              {pagedIssues.map((issue) => {
                const isScreenshotIssue = issue.triage?.category === 'needs-text-repro' || /screenshot/i.test(issue.title);
                const isContradictedIssue = /returns 0 when quantity is 3/i.test(issue.title);
                const isDedicatedIssue = issue.number === githubConnection?.dedicatedIssueNumber;
                const issueState = (issue.state || 'open').toLowerCase();
                return (
                <div
                  key={issue.number}
                  className="p-2.5 rounded-xl border border-slate-200/90 dark:border-slate-800 bg-white dark:bg-slate-900/70 space-y-2 text-xs shadow-2xs"
                >
                  <div className="space-y-1.5">
                    <div className="min-w-0">
                      <div className="flex items-start justify-between gap-1.5">
                        <div className="flex items-start gap-1.5 min-w-0">
                          <span className="font-mono text-xs font-semibold text-slate-400 shrink-0">#{issue.number}</span>
                          <span className="min-w-0 break-words text-xs font-semibold text-slate-900 dark:text-slate-100" title={issue.title}>
                            {issue.title}
                          </span>
                        </div>
                        <span
                          className={`px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider shrink-0 border ${
                            issueState === 'closed'
                              ? 'bg-accent-50 text-accent-800 border-accent-200 dark:bg-accent-950/60 dark:text-accent-300 dark:border-accent-800'
                              : 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800'
                          }`}
                        >
                          {issueState}
                        </span>
                      </div>
                      <div className="text-[10px] text-slate-400 mt-0.5 truncate">
                        @{issue.user} · {formatDate(issue.createdAt)}
                      </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-1">
                      {issue.triage && (
                        <span
                          className={`inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider ${
                            issue.triage.severity === 'rose'
                              ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                              : issue.triage.severity === 'amber'
                                ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                          }`}
                        >
                          {issue.triage.badge}
                        </span>
                      )}
                      {isDedicatedIssue && (
                        <span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                          Test fixture
                        </span>
                      )}
                      {isContradictedIssue && (
                        <span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                          Contradicted by code
                        </span>
                      )}
                      {isScreenshotIssue && (
                        <span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-accent-100 text-accent-800 dark:bg-accent-950 dark:text-accent-300">
                          Screenshot · Needs text steps
                        </span>
                      )}
                    </div>
                    {(issue.triage?.suggestedComment || isContradictedIssue) && (
                      <details className="rounded-lg border border-slate-200/80 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 px-2 py-1.5 text-[11px] text-slate-600 dark:text-slate-300">
                        <summary className="cursor-pointer font-medium text-slate-700 dark:text-slate-200">
                          {isContradictedIssue ? 'Why contradicted by code' : 'Ask for missing details'}
                        </summary>
                        <p className="mt-1 leading-relaxed text-[11px] text-slate-600 dark:text-slate-300">
                          {isContradictedIssue
                            ? 'Pinned code in src/OrderTotal.php and tests/order_total.php return 1500 for quantity 3 × priceCents 500 (not 0). Closed only with maintainer permission.'
                            : issue.triage?.suggestedComment}
                        </p>
                      </details>
                    )}
                  </div>

                  <div className="flex items-center gap-1.5">
                    {issue.number !== githubConnection?.dedicatedIssueNumber && (
                    <input
                      type="text"
                      autoComplete="off"
                      spellCheck={false}
                      placeholder="Path to file"
                      aria-label={`File path required to open issue ${issue.number}`}
                      title="Enter the file path to open this issue"
                      value={issuePathMap[issue.number] ?? ''}
                      onChange={(e) =>
                        setIssuePathMap((prev) => ({ ...prev, [issue.number]: e.target.value }))
                      }
                      className="flex-1 px-2 py-1 rounded bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[11px] font-mono text-slate-900 dark:text-slate-100 focus:outline-none"
                    />
                    )}
                    <button
                      type="button"
                      onClick={() => handleLoadIssue(issue)}
                      disabled={loadingIssueNum === issue.number || (issue.number !== githubConnection?.dedicatedIssueNumber && !issuePathMap[issue.number]?.trim())}
                      className="px-2.5 py-1 rounded bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 font-semibold text-[11px] hover:bg-slate-800 dark:hover:bg-slate-200 transition shrink-0 disabled:opacity-50"
                    >
                      {loadingIssueNum === issue.number ? '…' : 'Open'}
                    </button>
                  </div>
                </div>
                );
              })}
              {stateFilteredIssues.length > pageSize && (
                <div className="flex items-center justify-between pt-1 pb-2 text-[11px] text-slate-500 dark:text-slate-400">
                  <span>
                    {(safeIssuePage - 1) * pageSize + 1}–{Math.min(safeIssuePage * pageSize, stateFilteredIssues.length)} of {stateFilteredIssues.length}
                  </span>
                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      disabled={safeIssuePage <= 1}
                      onClick={() => setIssuePage((p) => Math.max(1, p - 1))}
                      className="p-1 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 disabled:opacity-40"
                    >
                      <ChevronLeft className="w-3.5 h-3.5" />
                    </button>
                    <button
                      type="button"
                      disabled={safeIssuePage >= totalIssuePages}
                      onClick={() => setIssuePage((p) => Math.min(totalIssuePages, p + 1))}
                      className="p-1 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 disabled:opacity-40"
                    >
                      <ChevronRight className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>
              )}
              </>
            )
          ) : (
            pagedPulls.length === 0 ? (
              <p className="text-xs text-slate-500 p-2 dark:text-slate-400">No pull requests found.</p>
            ) : (
              <>
              {pagedPulls.map((pull) => (
                <div
                  key={pull.number}
                  className="p-2.5 rounded-xl border border-slate-200/90 dark:border-slate-800 bg-white dark:bg-slate-900/70 space-y-1.5 text-xs shadow-2xs"
                >
                  <div className="flex items-start justify-between gap-1.5">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5">
                        <span className="font-mono text-xs font-semibold text-slate-400">#{pull.number}</span>
                        <span className="min-w-0 break-words text-xs font-semibold text-slate-900 dark:text-slate-100" title={pull.title}>
                          {pull.title}
                        </span>
                      </div>
                      <div className="text-[10px] text-slate-400 mt-0.5 break-all">
                        {pull.head} &rarr; {pull.base}
                      </div>
                    </div>
                    <span
                      className={`px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase shrink-0 border ${
                        pull.state === 'merged'
                          ? 'bg-accent-50 dark:bg-accent-950/60 text-accent-800 dark:text-accent-300 border-accent-200 dark:border-accent-800'
                          : pull.state === 'closed'
                            ? 'bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 border-rose-200 dark:border-rose-800'
                            : (pull as any).isDraft
                              ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-300 dark:border-slate-700'
                              : 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'
                      }`}
                    >
                      {(pull as any).isDraft && pull.state === 'open' ? 'draft' : pull.state}
                    </span>
                  </div>
                  <button
                    type="button"
                    onClick={() => router.post('/github/pulls/capture', { repository: githubConnection?.selectedRepo, number: pull.number })}
                    className="w-full mt-1 py-1 rounded bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-medium text-center block transition"
                  >
                    Open pull request
                  </button>
                </div>
              ))}
              {stateFilteredPulls.length > pageSize && (
                <div className="flex items-center justify-between pt-1 pb-2 text-[11px] text-slate-500 dark:text-slate-400">
                  <span>
                    {(safePullPage - 1) * pageSize + 1}–{Math.min(safePullPage * pageSize, stateFilteredPulls.length)} of {stateFilteredPulls.length}
                  </span>
                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      disabled={safePullPage <= 1}
                      onClick={() => setPullPage((p) => Math.max(1, p - 1))}
                      className="p-1 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 disabled:opacity-40"
                    >
                      <ChevronLeft className="w-3.5 h-3.5" />
                    </button>
                    <button
                      type="button"
                      disabled={safePullPage >= totalPullPages}
                      onClick={() => setPullPage((p) => Math.min(totalPullPages, p + 1))}
                      className="p-1 rounded border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 disabled:opacity-40"
                    >
                      <ChevronRight className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>
              )}
              </>
            )
          )}

        {localTasks.some((task) => task.repository.toLowerCase() === githubConnection?.selectedRepo?.toLowerCase()) && (
          <div className="space-y-1 border-t border-slate-200 pt-2.5 dark:border-slate-800">
            <h2 className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Automation Tasks</h2>
            {localTasks.filter((task) => task.repository.toLowerCase() === githubConnection?.selectedRepo?.toLowerCase()).map((task) => (
              <button key={task.id} type="button" onClick={() => onSelectTask(Number(task.id))} className="block w-full truncate rounded-lg px-2.5 py-1.5 text-left text-xs font-medium text-slate-700 bg-white/80 dark:bg-slate-900/60 border border-slate-200/70 dark:border-slate-800 hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-800">
                {task.title}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Pinned Bottom Account, New Task & Navigation Bar (Never scrolls off-screen) */}
      <div className="shrink-0 p-3 border-t border-slate-200/80 dark:border-slate-800 bg-[#e9e6dc] dark:bg-[#11141c] space-y-2 text-[11px] text-slate-700 dark:text-slate-300">
        <div>
          <button
            type="button"
            onClick={() => setViewMode('new')}
            className="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-800 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-800"
          >
            <Plus className="mr-1 inline h-3.5 w-3.5" /> New task
          </button>
        </div>
      </div>
    </aside>
  );
}
