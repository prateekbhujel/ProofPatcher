import React from 'react';
import {
  FolderGit2,
  CheckCircle2,
  ChevronUp,
  ChevronDown,
  AlertTriangle,
  LogOut,
  ExternalLink,
  Copy,
  Check,
  RefreshCw,
  Key,
  Lock,
  Sparkles,
} from 'lucide-react';
import { GitHubConnectionData, GitHubPull } from './types';

interface GitHubConnectionSectionProps {
  githubConnection: GitHubConnectionData | null;
  gitHubSectionOpen: boolean;
  setGitHubSectionOpen: (open: boolean) => void;
  clientIdInput: string;
  setClientIdInput: (v: string) => void;
  isSubmittingDevice: boolean;
  copiedUserCode: boolean;
  handleInitiateDeviceFlow: (e: React.FormEvent) => void;
  handlePollDeviceFlow: (e?: any) => void;
  handleDisconnectDevice: (e: React.FormEvent) => void;
  copyUserCode: (code: string) => void;
  handleSelectRepository: (fullName: string) => void;
  handleCaptureGitHubPull: (pr: any) => void;
}

export function GitHubConnectionSection({
  githubConnection,
  gitHubSectionOpen,
  setGitHubSectionOpen,
  clientIdInput,
  setClientIdInput,
  isSubmittingDevice,
  copiedUserCode,
  handleInitiateDeviceFlow,
  handlePollDeviceFlow,
  handleDisconnectDevice,
  copyUserCode,
  handleSelectRepository,
  handleCaptureGitHubPull,
}: GitHubConnectionSectionProps) {
  return (
    <div className="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden">
      <button
        type="button"
        onClick={() => setGitHubSectionOpen(!gitHubSectionOpen)}
        aria-expanded={gitHubSectionOpen}
        aria-controls="github-connection-details"
        className="w-full p-4 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between text-left cursor-pointer select-none hover:bg-slate-100/60 transition"
      >
        <div className="flex items-center gap-2.5">
          <FolderGit2 className="w-4 h-4 text-accent-700" />
          <div className="flex items-center gap-2">
            <span className="text-xs font-semibold text-slate-900">GitHub Connection</span>
          </div>
          {githubConnection?.isConnected ? (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
              <CheckCircle2 className="w-3 h-3 text-emerald-600" />
              Connected as @{githubConnection.user?.login}
            </span>
          ) : githubConnection?.deviceFlow ? (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-800 border border-amber-200">
              Code: {githubConnection.deviceFlow.userCode}
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200">
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
            <div className="p-3 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
              <span>{githubConnection.statusMessage}</span>
            </div>
          )}
          {githubConnection?.errorMessage && (
            <div className="p-3 rounded-md bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
              <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
              <span>{githubConnection.errorMessage}</span>
            </div>
          )}

          {/* State 1: Connected */}
          {githubConnection?.isConnected ? (
            <div className="space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-lg bg-slate-50 border border-slate-200">
                <div className="flex items-center gap-3">
                  {githubConnection.user?.avatarUrl ? (
                    <img
                      src={githubConnection.user.avatarUrl}
                      alt={githubConnection.user.login}
                      className="w-8 h-8 rounded-full border border-slate-300"
                    />
                  ) : (
                    <div className="w-8 h-8 rounded-full bg-accent-100 text-accent-700 flex items-center justify-center font-bold text-xs">
                      {githubConnection.user?.login.slice(0, 2).toUpperCase()}
                    </div>
                  )}
                  <div>
                    <div className="text-xs font-semibold text-slate-900 flex items-center gap-1.5">
                      <span>{githubConnection.user?.name || githubConnection.user?.login}</span>
                      <span className="text-slate-400 font-mono font-normal">(@{githubConnection.user?.login})</span>
                    </div>
                    <p className="text-[11px] text-slate-500">
                      {githubConnection.repositories.length} authorized {githubConnection.repositories.length === 1 ? 'repository' : 'repositories'} available
                    </p>
                  </div>
                </div>

                <form onSubmit={handleDisconnectDevice}>
                  <button
                    type="submit"
                    className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 shadow-2xs transition"
                  >
                    <LogOut className="w-3.5 h-3.5 text-slate-500" />
                    <span>Disconnect</span>
                  </button>
                </form>
              </div>

              {/* Selected Repositories List */}
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <h4 className="text-xs font-semibold text-slate-800">Installed Repositories (Least Privilege Scope)</h4>
                  <span className="text-[11px] text-slate-500">Selected during app installation</span>
                </div>

                {githubConnection.repositoriesStatus?.truncated && (
                  <div className="p-2.5 rounded-lg border border-amber-200 bg-amber-50 text-xs text-amber-800 flex items-start gap-2">
                    <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                    <div>
                      <span className="font-semibold">Repositories List Capped:</span> Additional repositories were omitted by pagination limits.
                    </div>
                  </div>
                )}
                {githubConnection.repositoriesStatus?.errors && githubConnection.repositoriesStatus.errors.length > 0 && (
                  <div className="p-2.5 rounded-lg border border-rose-200 bg-rose-50 text-xs text-rose-800 flex items-start gap-2">
                    <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
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
                  <div className="p-4 rounded-lg border border-slate-200 bg-slate-50 text-center text-xs text-slate-500 space-y-1">
                    <p className="font-medium text-slate-700">No repositories granted to this installation.</p>
                    <p className="text-[11px]">
                      Configure repository permissions on GitHub to grant ProofPatcher access to selected owned repositories.
                    </p>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    {githubConnection.repositories.map((repo) => {
                      const isCurrent = githubConnection.selectedRepo === repo.fullName;
                      return (
                        <button
                          key={repo.fullName}
                          type="button"
                          onClick={() => handleSelectRepository(repo.fullName)}
                          className={`p-2.5 rounded-lg border text-left transition flex items-center justify-between gap-2 ${
                            isCurrent
                              ? 'bg-accent-50/70 border-accent-300 ring-1 ring-accent-400'
                              : 'bg-white border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                          }`}
                        >
                          <div className="min-w-0">
                            <div className="text-xs font-semibold text-slate-900 truncate">{repo.fullName}</div>
                            <div className="text-[10px] text-slate-500 flex items-center gap-1.5 mt-0.5">
                              <span>{repo.defaultBranch}</span>
                              <span>·</span>
                              <span className={repo.isPrivate ? 'text-amber-700 font-medium' : 'text-emerald-700 font-medium'}>
                                {repo.isPrivate ? 'Private' : 'Public'}
                              </span>
                            </div>
                          </div>
                          <span
                            className={`text-[10px] px-2 py-0.5 rounded font-medium ${
                              isCurrent ? 'bg-accent-700 text-white' : 'bg-slate-100 text-slate-600'
                            }`}
                          >
                            {isCurrent ? 'Selected' : 'Select'}
                          </span>
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>

              {/* Pull Requests in Selected Repo */}
              {githubConnection.selectedRepo && (
                <div className="space-y-2 pt-2 border-t border-slate-100">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-semibold text-slate-800">
                      Pull Requests in <span className="font-mono text-accent-900">{githubConnection.selectedRepo}</span>
                    </h4>
                    <span className="text-[11px] text-slate-500 font-mono">
                      {githubConnection.repoPulls.length} total
                    </span>
                  </div>

                  {githubConnection.repoPullsStatus?.truncated && (
                    <div className="p-2.5 rounded-lg border border-amber-200 bg-amber-50 text-xs text-amber-800 flex items-start gap-2">
                      <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                      <div>
                        <span className="font-semibold">Pull Requests Capped:</span> Showing first 500 open pull requests.
                      </div>
                    </div>
                  )}
                  {githubConnection.repoPullsStatus?.errors && githubConnection.repoPullsStatus.errors.length > 0 && (
                    <div className="p-2.5 rounded-lg border border-rose-200 bg-rose-50 text-xs text-rose-800 flex items-start gap-2">
                      <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
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

                  {githubConnection.repoPulls.length === 0 ? (
                    <div className="p-4 rounded-lg border border-slate-200 bg-slate-50 text-center text-xs text-slate-500">
                      No open pull requests found in {githubConnection.selectedRepo}.
                    </div>
                  ) : (
                    <div className="divide-y divide-slate-100 border border-slate-200 rounded-lg overflow-hidden bg-white">
                      {githubConnection.repoPulls.map((pr) => (
                        <div
                          key={pr.number}
                          className="p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 transition"
                        >
                          <div className="space-y-1 min-w-0">
                            <div className="flex items-center gap-2">
                              <span className="font-bold text-xs text-slate-900">#{pr.number}</span>
                              <span
                                className={`px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase border ${
                                  (pr as any).state === 'merged'
                                    ? 'bg-accent-50 text-accent-700 border-accent-200'
                                    : (pr as any).state === 'closed'
                                    ? 'bg-rose-50 text-rose-700 border-rose-200'
                                    : (pr as any).isDraft
                                    ? 'bg-slate-100 text-slate-700 border-slate-300'
                                    : 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                }`}
                              >
                                {(pr as any).isDraft && (pr as any).state === 'open' ? 'DRAFT' : ((pr as any).state || 'OPEN')}
                              </span>
                              <span className="text-xs font-medium text-slate-800 truncate">{pr.title}</span>
                              {pr.isPrivate && (
                                <span className="inline-flex items-center gap-0.5 px-1.5 py-0.2 rounded text-[10px] font-medium bg-amber-50 text-amber-800 border border-amber-200">
                                  <Lock className="w-2.5 h-2.5 text-amber-700" />
                                  Private
                                </span>
                              )}
                            </div>
                            <div className="text-[11px] text-slate-500 flex flex-wrap items-center gap-2 font-mono">
                              <span>by @{pr.user}</span>
                              <span>·</span>
                              <span>{pr.head} → {pr.base}</span>
                            </div>
                          </div>

                          {pr.isPrivate && !githubConnection.canCapturePrivateTestRepo ? (
                            <span className="inline-flex items-center px-2 py-1 rounded text-[11px] font-medium text-amber-700 bg-amber-50 border border-amber-200">
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
          ) : githubConnection?.deviceFlow ? (
            /* State 2: Device Code Awaiting Authorization */
            <div className="p-4 rounded-lg bg-amber-50/70 border border-amber-200 space-y-3">
              <div className="space-y-1">
                <div className="text-xs font-semibold text-amber-900">Authorize Device in Your Browser</div>
                <p className="text-xs text-amber-800">
                  Open GitHub, verify the code below, and authorize ProofPatcher. No personal access tokens or secrets are transferred.
                </p>
              </div>

              <div className="flex flex-col sm:flex-row sm:items-center gap-3 p-3 bg-white rounded-md border border-amber-200">
                <div className="flex-1">
                  <span className="text-[10px] uppercase font-bold text-slate-400 tracking-wider block">One-Time User Code</span>
                  <div className="flex items-center gap-2 mt-0.5">
                    <code className="text-lg font-mono font-bold text-accent-700 tracking-widest">
                      {githubConnection.deviceFlow.userCode}
                    </code>
                    <button
                      type="button"
                      onClick={() => copyUserCode(githubConnection.deviceFlow!.userCode)}
                      className="p-1 rounded text-slate-400 hover:text-slate-600"
                      title="Copy code"
                    >
                      {copiedUserCode ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                    </button>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <a
                    href={githubConnection.deviceFlow.verificationUri || 'https://github.com/login/device'}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-accent-700 text-white text-xs font-semibold hover:bg-accent-800 shadow-2xs transition"
                  >
                    <span>Open GitHub Device Page</span>
                    <ExternalLink className="w-3.5 h-3.5" />
                  </a>

                  <button
                    type="button"
                    onClick={() => handlePollDeviceFlow()}
                    disabled={isSubmittingDevice}
                    className="inline-flex items-center gap-1 px-3 py-1.5 rounded-md bg-white border border-slate-300 text-slate-700 text-xs font-semibold hover:bg-slate-50 transition disabled:opacity-50"
                  >
                    <RefreshCw className={`w-3.5 h-3.5 ${isSubmittingDevice ? 'animate-spin' : ''}`} />
                    <span>Check Status</span>
                  </button>
                </div>
              </div>
            </div>
          ) : (
            /* State 3: Disconnected - Initiate Flow */
            <form onSubmit={handleInitiateDeviceFlow} className="space-y-3">
              <div className="space-y-1">
                <label htmlFor="client_id" className="block text-xs font-medium text-slate-700">
                  GitHub App Client ID
                </label>
                <input
                  type="text"
                  id="client_id"
                  value={clientIdInput}
                  onChange={(e) => setClientIdInput(e.target.value)}
                  placeholder="e.g., Ov23li... (configured in Settings)"
                  className="w-full text-xs font-mono rounded-md border border-slate-300 px-3 py-2 text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-1 focus:ring-accent-500"
                />
                <p className="text-[11px] text-slate-500">
                  Connect via GitHub OAuth Device Flow (RFC 8628) with user-code authorization.
                </p>
              </div>

              <button
                type="submit"
                disabled={isSubmittingDevice}
                className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-md bg-accent-700 text-white text-xs font-semibold hover:bg-accent-800 transition shadow-2xs disabled:opacity-50"
              >
                <Key className="w-3.5 h-3.5" />
                <span>{isSubmittingDevice ? 'Starting…' : 'Initiate Device Connection'}</span>
              </button>
            </form>
          )}
        </div>
      )}
    </div>
  );
}
