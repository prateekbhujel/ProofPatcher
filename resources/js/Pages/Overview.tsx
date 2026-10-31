import React, { useState, useMemo } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';
import {
  GitPullRequest,
  ArrowRight,
  Search,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Settings,
} from 'lucide-react';

interface PullItem {
  id: string;
  pull: {
    repository: string;
    number: number;
    title: string;
    state: string;
    headSha: string;
    isMerged?: boolean;
    isPrivate?: boolean;
  };
  capturedAt: string;
}

interface OverviewProps {
  totalSnapshots: number;
  totalRuns: number;
  verifiedRuns: number;
  recent: PullItem[];
}

export default function Overview({
  totalSnapshots = 0,
  totalRuns = 0,
  verifiedRuns = 0,
  recent = [],
}: OverviewProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const pageSize = 5;

  const formatTime = (ts: string) => {
    try {
      return new Date(ts).toISOString().replace('T', ' ').slice(0, 16) + ' UTC';
    } catch {
      return ts;
    }
  };

  const filteredPulls = useMemo(() => {
    if (!searchQuery.trim()) return recent;
    const q = searchQuery.toLowerCase();
    return recent.filter(
      (item) =>
        item.pull.title.toLowerCase().includes(q) ||
        item.pull.repository.toLowerCase().includes(q) ||
        item.pull.headSha.toLowerCase().includes(q) ||
        `#${item.pull.number}`.includes(q)
    );
  }, [recent, searchQuery]);

  const totalPages = Math.max(1, Math.ceil(filteredPulls.length / pageSize));
  const safeCurrentPage = Math.min(currentPage, totalPages);

  const paginatedPulls = useMemo(() => {
    const start = (safeCurrentPage - 1) * pageSize;
    return filteredPulls.slice(start, start + pageSize);
  }, [filteredPulls, safeCurrentPage, pageSize]);

  return (
    <AppLayout activeNav="overview">
      <Head title="Overview · ProofPatcher" />

      <div className="space-y-5 w-full">
        <section className="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#141821] p-5 sm:p-6">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h1 className="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Overview</h1>
              <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                Review pull requests, verify bug fixes in isolated environments, and prepare draft changes.
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2.5">
              <Link
                href="/repositories"
                className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 transition hover:bg-slate-50"
              >
                <GitPullRequest className="h-3.5 w-3.5 text-slate-600" />
                <span>Review Pull Requests</span>
              </Link>
              <Link
                href="/issues"
                className="inline-flex items-center gap-2 rounded-lg bg-accent-700 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-accent-800"
              >
                <span>Open Issues &amp; Tasks</span>
                <ArrowRight className="h-3.5 w-3.5" />
              </Link>
            </div>
          </div>

          <dl className="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 dark:border-slate-800 pt-5 sm:grid-cols-4">
            <div className="rounded-lg border border-slate-200 p-3.5 dark:border-slate-700">
              <dt className="text-[11px] font-medium text-slate-500">Provider balance</dt>
              <dd className="mt-1 text-sm font-medium">Unavailable</dd>
              <p className="text-[10px] text-slate-400">Check your provider dashboard</p>
            </div>
            <div className="rounded-lg border border-slate-200/80 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-900/50 p-3.5 space-y-1">
              <dt className="text-[11px] font-medium text-slate-500">Local Verifications</dt>
              <dd className="text-xl font-bold text-slate-900 dark:text-slate-100 tabular-nums">{totalRuns}</dd>
              <p className="text-[10px] text-emerald-600 font-medium">{verifiedRuns} verified passes</p>
            </div>
            <div className="rounded-lg border border-slate-200/80 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-900/50 p-3.5 space-y-1">
              <dt className="text-[11px] font-medium text-slate-500">PR Snapshots</dt>
              <dd className="text-xl font-bold text-slate-900 dark:text-slate-100 tabular-nums">{totalSnapshots}</dd>
              <p className="text-[10px] text-slate-400">Captured for review</p>
            </div>
          </dl>
        </section>

        {/* Core Workspaces Navigation (3-Column Row) */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <Link
            href="/repositories"
            className="p-4 rounded-xl border border-slate-200 bg-white hover:border-accent-300 transition group"
          >
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <span className="w-8 h-8 rounded-lg bg-accent-50 text-accent-700 flex items-center justify-center font-semibold text-xs border border-accent-200/80 group-hover:bg-accent-700 group-hover:text-white transition">
                  <GitPullRequest className="w-4 h-4" />
                </span>
                <span className="text-xs font-semibold text-slate-900 group-hover:text-accent-700 transition">
                  Pull Requests
                </span>
              </div>
              <ArrowRight className="w-3.5 h-3.5 text-slate-400 group-hover:text-accent-700 transition" />
            </div>
            <p className="text-xs text-slate-500 mt-2 leading-relaxed">
              Capture pull request snapshots, inspect diffs, and post approved line-anchored review comments.
            </p>
          </Link>

          <Link
            href="/issues"
            className="p-4 rounded-xl border border-slate-200 bg-white hover:border-accent-300 transition group"
          >
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <span className="w-8 h-8 rounded-lg bg-accent-50 text-accent-700 flex items-center justify-center font-semibold text-xs border border-accent-200/80 group-hover:bg-accent-700 group-hover:text-white transition">
                  <ClipboardList className="w-4 h-4" />
                </span>
                <span className="text-xs font-semibold text-slate-900 group-hover:text-accent-700 transition">
                  Issues &amp; Tasks
                </span>
              </div>
              <ArrowRight className="w-3.5 h-3.5 text-slate-400 group-hover:text-accent-700 transition" />
            </div>
            <p className="text-xs text-slate-500 mt-2 leading-relaxed">
              Review repository issues, test candidate fixes in an isolated microVM, and prepare draft pull requests.
            </p>
          </Link>

          <Link
            href="/settings"
            className="p-4 rounded-xl border border-slate-200 bg-white hover:border-accent-300 transition group"
          >
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <span className="w-8 h-8 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center font-semibold text-xs border border-slate-200 group-hover:bg-accent-700 group-hover:text-white transition">
                  <Settings className="w-4 h-4" />
                </span>
                <span className="text-xs font-semibold text-slate-900 group-hover:text-accent-700 transition">
                  GitHub App &amp; Budget
                </span>
              </div>
              <ArrowRight className="w-3.5 h-3.5 text-slate-400 group-hover:text-accent-700 transition" />
            </div>
            <p className="text-xs text-slate-500 mt-2 leading-relaxed">
              Connect your GitHub App via Device Flow, select repositories, and review local model request usage.
            </p>
          </Link>
        </div>

        {/* Captured Pull Requests Datatable */}
        <section className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div className="px-5 py-3.5 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/50">
            <div>
              <div className="flex items-center gap-2">
                <h2 id="captured-prs-heading" className="text-sm font-semibold text-slate-900">
                  Captured Pull Requests
                </h2>
                <span className="text-[11px] px-2 py-0.5 rounded-full font-medium bg-slate-100 text-slate-700 border border-slate-200 tabular-nums">
                  {recent.length}
                </span>
              </div>
              <p className="text-xs text-slate-500 mt-0.5">
                Saved snapshots ready for diff inspection and line-anchored review comments.
              </p>
            </div>

            {/* Filter Input */}
            <div className="relative w-full sm:w-64">
              <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                id="global-search-input"
                type="text"
                autoComplete="off"
                autoCorrect="off"
                autoCapitalize="off"
                spellCheck={false}
                aria-label="Filter captured pull requests"
                placeholder="Filter by repo, title, or SHA…"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
                className="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-slate-300 bg-white placeholder-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:border-transparent transition"
              />
            </div>
          </div>

          {filteredPulls.length === 0 ? (
            <div className="p-8 text-center space-y-2">
              <p className="text-xs text-slate-500">
                {searchQuery ? 'No pull requests matched your filter.' : 'No pull request snapshots captured yet.'}
              </p>
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => setSearchQuery('')}
                  className="text-xs text-accent-700 hover:underline font-medium"
                >
                  Clear filter
                </button>
              )}
            </div>
          ) : (
            <>
              <div
                tabIndex={0}
                role="region"
                aria-labelledby="captured-prs-heading"
                className="overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-accent-600"
              >
                <table className="w-full text-left border-collapse">
                  <thead>
                    <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-semibold text-slate-600 uppercase tracking-wider">
                      <th scope="col" className="sticky top-0 z-10 bg-slate-50 py-2.5 px-5">
                        Repository &amp; Pull Request
                      </th>
                      <th scope="col" className="sticky top-0 z-10 bg-slate-50 py-2.5 px-5">
                        Commit SHA
                      </th>
                      <th scope="col" className="sticky top-0 z-10 bg-slate-50 py-2.5 px-5">
                        State
                      </th>
                      <th scope="col" className="sticky top-0 z-10 bg-slate-50 py-2.5 px-5">
                        Captured At
                      </th>
                      <th scope="col" className="sticky top-0 z-10 bg-slate-50 py-2.5 px-5 text-right">
                        Action
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 text-xs">
                    {paginatedPulls.map((item) => {
                      const isMerged = item.pull.isMerged || item.pull.state.toLowerCase() === 'merged';
                      const isOpen = item.pull.state.toLowerCase() === 'open';

                      return (
                        <tr
                          key={item.id}
                          className="hover:bg-slate-50/70 transition-colors group"
                        >
                          <td className="py-3 px-5 max-w-sm">
                            <div className="flex items-center gap-2">
                              <span className="font-semibold text-slate-900 truncate">
                                {item.pull.repository}#{item.pull.number}
                              </span>
                            </div>
                            <div className="text-slate-600 truncate mt-0.5 text-[11px]">
                              {item.pull.title}
                            </div>
                          </td>
                          <td className="py-3 px-5 whitespace-nowrap">
                            <code className="text-[11px] font-mono bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded border border-slate-200">
                              {item.pull.headSha.slice(0, 8)}
                            </code>
                          </td>
                          <td className="py-3 px-5 whitespace-nowrap">
                            <span
                              className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold ${
                                isMerged
                                  ? 'bg-accent-50 text-accent-800 border border-accent-200'
                                  : isOpen
                                  ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                                  : 'bg-slate-100 text-slate-700 border border-slate-200'
                              }`}
                            >
                              {isMerged ? 'MERGED' : item.pull.state.toUpperCase()}
                            </span>
                          </td>
                          <td className="py-3 px-5 text-slate-500 whitespace-nowrap font-mono text-[11px]">
                            {formatTime(item.capturedAt)}
                          </td>
                          <td className="py-3 px-5 text-right whitespace-nowrap">
                            <Link
                              href={`/repositories?snapshot=${encodeURIComponent(item.id)}`}
                              className="inline-flex items-center gap-1 text-xs font-semibold text-slate-900 group-hover:text-accent-700 hover:underline"
                            >
                              <span>Inspect</span>
                              <ArrowRight className="w-3 h-3" />
                            </Link>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Pagination Controls */}
              {totalPages > 1 && (
                <div className="px-5 py-2.5 border-t border-slate-200 flex items-center justify-between text-xs text-slate-600 bg-slate-50/40">
                  <span className="text-[11px] text-slate-500 tabular-nums">
                    Showing {(safeCurrentPage - 1) * pageSize + 1} to{' '}
                    {Math.min(safeCurrentPage * pageSize, filteredPulls.length)} of {filteredPulls.length} snapshots
                  </span>

                  <div className="flex items-center gap-1.5">
                    <button
                      type="button"
                      onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                      disabled={safeCurrentPage === 1}
                      className="inline-flex items-center gap-1 px-2.5 py-1 rounded border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                      <ChevronLeft className="w-3.5 h-3.5" />
                      <span>Prev</span>
                    </button>

                    <div className="flex items-center gap-1">
                      {Array.from({ length: totalPages }, (_, i) => i + 1).map((pageNum) => (
                        <button
                          key={pageNum}
                          type="button"
                          onClick={() => setCurrentPage(pageNum)}
                          className={`w-6 h-6 rounded text-xs font-medium flex items-center justify-center transition ${
                            pageNum === safeCurrentPage
                              ? 'bg-accent-700 text-white font-semibold'
                              : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700'
                          }`}
                        >
                          {pageNum}
                        </button>
                      ))}
                    </div>

                    <button
                      type="button"
                      onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                      disabled={safeCurrentPage === totalPages}
                      className="inline-flex items-center gap-1 px-2.5 py-1 rounded border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                      <span>Next</span>
                      <ChevronRight className="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </section>
      </div>
    </AppLayout>
  );
}
