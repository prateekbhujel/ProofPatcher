import React, { useState } from 'react';
import {
  FolderGit2,
  CheckCircle2,
  ChevronUp,
  ChevronDown,
  AlertTriangle,
  LogOut,
  Lock,
  Sparkles,
} from 'lucide-react';
import { GitHubConnectionData, GitHubPull } from './types';
import { SignInWithGitHubButton } from '../ui/SignInWithGitHubButton';
import { StateChip } from '../console/primitives';

const REPO_PAGE_SIZE = 12;

interface GitHubConnectionSectionProps {
  githubConnection: GitHubConnectionData | null;
  gitHubSectionOpen: boolean;
  setGitHubSectionOpen: (open: boolean) => void;
  handleDisconnectDevice: (e: React.FormEvent) => void;
  handleSelectRepository: (fullName: string) => void;
  handleCaptureGitHubPull: (pr: any) => void;
}

export function GitHubConnectionSection({
  githubConnection,
  gitHubSectionOpen,
  setGitHubSectionOpen,
  handleDisconnectDevice,
  handleSelectRepository,
  handleCaptureGitHubPull,
}: GitHubConnectionSectionProps) {
  const [repoQuery, setRepoQuery] = useState('');
  const [repoPage, setRepoPage] = useState(0);
  return (
    <div className="border border-[var(--border)] bg-[var(--bg-raised)] overflow-hidden">
      <button
        type="button"
        onClick={() => setGitHubSectionOpen(!gitHubSectionOpen)}
        aria-expanded={gitHubSectionOpen}
        aria-controls="github-connection-details"
        className="w-full p-4 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between text-left cursor-pointer select-none hover:bg-slate-100/60 transition dark:bg-slate-900/70 dark:border-slate-800 dark:hover:bg-slate-700/60"
      >
        <div className="flex items-center gap-2.5">
          <FolderGit2 className="w-4 h-4 text-accent-700 dark:text-accent-300" />
          <div className="flex items-center gap-2">
            <span className="text-xs font-semibold text-slate-900 dark:text-slate-100">GitHub Connection</span>
          </div>
          {githubConnection?.isConnected ? (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400">
              <CheckCircle2 className="w-3 h-3 text-emerald-600 dark:text-emerald-400" />
              Connected as @{githubConnection.user?.login}
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:border-slate-800 dark:text-slate-400">
              Not Connected
            </span>
          )}
        </div>
        <div className="flex items-center gap-2 text-slate-400">
          {gitHubSectionOpen ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
        </div>
      </button>

      {gitHubSectionOpen && (
        <div id="github-connection-details" className="p-5 space-y-4">
          {/* Feedback messages */}
          {githubConnection?.statusMessage && (
            <div className="p-3 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-300">
              <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 dark:text-emerald-400" />
              <span>{githubConnection.statusMessage}</span>
            </div>
          )}
          {githubConnection?.errorMessage && (
            <div className="p-3 rounded-md bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
              <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 dark:text-rose-400" />
              <span>{githubConnection.errorMessage}</span>
            </div>
          )}

          {/* State 1: Connected */}
          {githubConnection?.isConnected ? (
            <div className="space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-lg bg-slate-50 border border-slate-200 dark:bg-slate-900 dark:border-slate-800">
                <div className="flex items-center gap-3">
                  {githubConnection.user?.avatarUrl ? (
                    <img
                      src={githubConnection.user.avatarUrl}
                      alt={githubConnection.user.login}
                      className="w-8 h-8 rounded-full border border-slate-300 dark:border-slate-700"
                    />
                  ) : (
                    <div className="w-8 h-8 rounded-full bg-accent-100 text-accent-700 flex items-center justify-center font-bold text-xs dark:bg-accent-950/60 dark:text-accent-300">
                      {githubConnection.user?.login.slice(0, 2).toUpperCase()}
                    </div>
                  )}
                  <div>
                    <div className="text-xs font-semibold text-slate-900 flex items-center gap-1.5 dark:text-slate-100">
                      <span>{githubConnection.user?.name || githubConnection.user?.login}</span>
                      <span className="text-slate-400 font-mono font-normal">(@{githubConnection.user?.login})</span>
                    </div>
                    <p className="text-[11px] text-slate-500 dark:text-slate-400">
                      {githubConnection.repositories.length} authorized {githubConnection.repositories.length === 1 ? 'repository' : 'repositories'} available
                    </p>
                  </div>
                </div>

                <form onSubmit={handleDisconnectDevice}>
                  <button
                    type="submit"
                    className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 shadow-2xs transition dark:bg-[#141821] dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                  >
                    <LogOut className="w-3.5 h-3.5 text-slate-500 dark:text-slate-400" />
                    <span>Disconnect</span>
                  </button>
                </form>
              </div>

              {/* Selected Repositories List */}
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <h4 className="text-xs font-semibold text-slate-800 dark:text-slate-200">Installed Repositories (Least Privilege Scope)</h4>
                  <span className="text-[11px] text-slate-500 dark:text-slate-400">Selected during app installation</span>
                </div>

                {githubConnection.repositoriesStatus?.truncated && (
                  <div className="p-2.5 rounded-lg border border-amber-200 bg-amber-50 text-xs text-amber-800 flex items-start gap-2 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-300">
                    <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5 dark:text-amber-400" />
                    <div>
                      <span className="font-semibold">Repositories List Capped:</span> Additional repositories were omitted by pagination limits.
                    </div>
                  </div>
                )}
                {githubConnection.repositoriesStatus?.errors && githubConnection.repositoriesStatus.errors.length > 0 && (
                  <div className="p-2.5 rounded-lg border border-rose-200 bg-rose-50 text-xs text-rose-800 flex items-start gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
                    <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5 dark:text-rose-400" />
                    <div>
                      <span className="font-semibold">Partial Installation Errors:</span>
                      <ul className="list-disc list-inside mt-0.5 font-mono text-[11px]">
                        {githubConnection.repositoriesStatus.errors.map((err, idx) => (
                          <li key={idx}>{err}</li>
                        ))}
                      </ul>
                    </div>
                  </div>
                )}

                {githubConnection.repositories.length === 0 ? (
                  <div className="p-4 rounded-lg border border-slate-200 bg-slate-50 text-center text-xs text-slate-500 space-y-1 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-400">
                    <p className="font-medium text-slate-700 dark:text-slate-300">No repositories granted to this installation.</p>
                    <p className="text-[11px]">
                      Configure repository permissions on GitHub to grant ProofPatcher access to selected owned repositories.
                    </p>
                  </div>
                ) : (
                  (() => {
                    const query = repoQuery.trim().toLowerCase();
                    const filtered = query
                      ? githubConnection.repositories.filter((r) => r.fullName.toLowerCase().includes(query))
                      : githubConnection.repositories;
                    const pageCount = Math.max(1, Math.ceil(filtered.length / REPO_PAGE_SIZE));
                    const page = Math.min(repoPage, pageCount - 1);
                    const visible = filtered.slice(page * REPO_PAGE_SIZE, page * REPO_PAGE_SIZE + REPO_PAGE_SIZE);
                    return (
                      <div className="space-y-2">
                        <input
                          type="search"
                          value={repoQuery}
                          onChange={(e) => { setRepoQuery(e.target.value); setRepoPage(0); }}
                          placeholder="Search repositories…"
                          aria-label="Search repositories"
                          className="w-full border border-[var(--border-strong)] bg-transparent px-2.5 py-1.5 text-xs"
                        />
                        <div className="divide-y divide-[var(--border)] border border-[var(--border)] bg-[var(--bg-raised)]">
                          {visible.map((repo) => {
                            const isCurrent = githubConnection.selectedRepo === repo.fullName;
                            return (
                              <button
                                key={repo.fullName}
                                type="button"
                                onClick={() => handleSelectRepository(repo.fullName)}
                                className={`flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-xs ${isCurrent ? 'bg-[var(--accent-soft)]' : 'hover:bg-[var(--bg-hover)]'}`}
                              >
                                <span className="min-w-0 flex-1 truncate font-medium">{repo.fullName}</span>
                                <span className="shrink-0 text-[var(--fg-muted)]">{repo.defaultBranch}</span>
                                {repo.isPrivate ? <StateChip state="private" /> : <span className="shrink-0 text-[10px] uppercase text-[var(--fg-subtle)]">public</span>}
                                <span className={`shrink-0 font-semibold ${isCurrent ? 'text-[var(--accent-text)]' : 'text-[var(--fg-muted)]'}`}>{isCurrent ? 'selected' : 'select'}</span>
                              </button>
                            );
                          })}
                          {visible.length === 0 && <p className="px-2.5 py-2 text-xs text-[var(--fg-muted)]">No repositories match.</p>}
                        </div>
                        <div className="flex items-center justify-between text-[11px] text-[var(--fg-muted)]">
                          <span>{filtered.length} repositories · page {page + 1} of {pageCount}</span>
                          <span className="flex gap-2">
                            <button type="button" disabled={page === 0} onClick={() => setRepoPage(page - 1)} className="font-semibold disabled:opacity-40">← prev</button>
                            <button type="button" disabled={page >= pageCount - 1} onClick={() => setRepoPage(page + 1)} className="font-semibold disabled:opacity-40">next →</button>
                          </span>
                        </div>
                      </div>
                    );
                  })()
                )}
              </div>

              {/* Pull Requests in Selected Repo */}
              {githubConnection.selectedRepo && (
                <div className="space-y-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-semibold text-slate-800 dark:text-slate-200">
                      Pull Requests in <span className="font-mono text-accent-900 dark:text-accent-300">{githubConnection.selectedRepo}</span>
                    </h4>
                    <span className="text-[11px] text-slate-500 font-mono dark:text-slate-400">
                      {githubConnection.repoPullsLoaded ? `${githubConnection.repoPulls.length} loaded` : 'not loaded'}
                    </span>
                  </div>

                  {githubConnection.repoPullsStatus?.truncated && (
                    <div className="p-2.5 rounded-lg border border-amber-200 bg-amber-50 text-xs text-amber-800 flex items-start gap-2 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-300">
                      <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5 dark:text-amber-400" />
                      <div>
                        <span className="font-semibold">Pull Requests Capped:</span> Only some pull requests were loaded.
                      </div>
                    </div>
                  )}
                  {githubConnection.repoPullsStatus?.errors && githubConnection.repoPullsStatus.errors.length > 0 && (
                    <div className="p-2.5 rounded-lg border border-rose-200 bg-rose-50 text-xs text-rose-800 flex items-start gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
                      <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5 dark:text-rose-400" />
                      <div>
                        <span className="font-semibold">Partial Retrieval Errors:</span>
                        <ul className="list-disc list-inside mt-0.5 font-mono text-[11px]">
                          {githubConnection.repoPullsStatus.errors.map((err, idx) => (
                            <li key={idx}>{err}</li>
                          ))}
                        </ul>
                      </div>
                    </div>
                  )}

                  {!githubConnection.repoPullsLoaded ? (
                    <div className="p-4 rounded-lg border border-slate-200 bg-slate-50 text-center text-xs text-slate-500 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-400">
                      Pull requests have not been loaded.{' '}
                      <button type="button" onClick={() => handleSelectRepository(githubConnection.selectedRepo!)} className="font-semibold underline">Load pull requests</button>
                    </div>
                  ) : githubConnection.repoPulls.length === 0 ? (
                    <div className="p-4 rounded-lg border border-slate-200 bg-slate-50 text-center text-xs text-slate-500 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-400">
                      {githubConnection.repoPullsStatus?.truncated || githubConnection.repoPullsStatus?.errors?.length
                        ? 'The list is incomplete. Refresh to try again.'
                        : `No pull requests found in ${githubConnection.selectedRepo}.`}
                    </div>
                  ) : (
                    <div className="divide-y divide-slate-100 border border-slate-200 rounded-lg overflow-hidden bg-white dark:bg-[#141821] dark:border-slate-800 dark:divide-slate-800">
                      {githubConnection.repoPulls.map((pr) => (
                        <div
                          key={pr.number}
                          className="p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 transition dark:hover:bg-slate-800/60"
                        >
                          <div className="space-y-1 min-w-0">
                            <div className="flex items-center gap-2">
                              <span className="font-bold text-xs text-slate-900 dark:text-slate-100">#{pr.number}</span>
                              <StateChip state={(pr as any).isDraft && ((pr as any).state || 'open') === 'open' ? 'draft' : ((pr as any).state || 'open')} />
                              <span className="text-xs font-medium text-slate-800 truncate dark:text-slate-200">{pr.title}</span>
                              {pr.isPrivate && <StateChip state="private" />}
                            </div>
                            <div className="text-[11px] text-slate-500 flex flex-wrap items-center gap-2 font-mono dark:text-slate-400">
                              <span>by @{pr.user}</span>
                              <span>·</span>
                              <span>{pr.head} → {pr.base}</span>
                            </div>
                          </div>

                          {pr.isPrivate && !githubConnection.canCapturePrivateTestRepo ? (
                            <span className="inline-flex items-center px-2 py-1 rounded text-[11px] font-medium text-amber-700 bg-amber-50 border border-amber-200 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-400">
                              Private PR capture disabled
                            </span>
                          ) : (
                            <button
                              type="button"
                              onClick={() => handleCaptureGitHubPull(pr)}
                              className="inline-flex items-center justify-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md text-white bg-accent-700 hover:bg-accent-800 shadow-2xs motion-safe:transition motion-reduce:transition-none shrink-0"
                            >
                              <Sparkles className="w-3.5 h-3.5" />
                              <span>Review PR</span>
                            </button>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          ) : (
            /* Disconnected: one-click web sign-in. */
            <div className="space-y-3">
              <p className="text-xs text-slate-600 dark:text-slate-400">
                Sign in to load your repositories and pull requests. GitHub will ask you to authorize ProofPatcher, then bring you back here.
              </p>
              <SignInWithGitHubButton redirectTo="/repositories" />
            </div>
          )}
        </div>
      )}
    </div>
  );
}
