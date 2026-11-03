import React, { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';
import { SignInWithGitHubButton } from '../Components/ui/SignInWithGitHubButton';
import { budgetStatusLine, sharedBudgetNote } from '../Components/ui/budgetCopy';
import { ThemeToggle } from '../Components/ThemeToggle';
import {
  Shield,
  Key,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Sliders,
  Check,
  FolderGit2,
  GitPullRequest,
  Search,
  BarChart3,
} from 'lucide-react';
import { SettingsProps } from '../Components/settings/types';
import { AutoReviewPanel } from '../Components/settings/AutoReviewPanel';
import { AutomaticCommentsPanel } from '../Components/settings/AutomaticCommentsPanel';
import { MaintainerAutopilotPanel } from '../Components/settings/MaintainerAutopilotPanel';
import { AutopilotBudgetCard } from '../Components/settings/AutopilotBudgetCard';

const SETTINGS_SECTIONS = ['github', 'review', 'provider', 'autopilot', 'comments', 'usage', 'general'] as const;
type SettingsSection = typeof SETTINGS_SECTIONS[number];

export function sectionFromHash(hash: string): SettingsSection {
  const name = hash.replace(/^#settings-/, '');
  if (name === 'maintainer') return 'autopilot';
  return SETTINGS_SECTIONS.includes(name as SettingsSection) ? name as SettingsSection : 'github';
}

export default function Settings({
  githubConnection,
  budget,
  providerConfig,
  autopilotBudget,
  statusMessage,
  errorMessage,
  autoReview,
}: SettingsProps) {
  const { props } = usePage();

  const [activeSection, setActiveSection] = useState<SettingsSection>(() =>
    typeof window === 'undefined' ? 'github' : sectionFromHash(window.location.hash)
  );
  useEffect(() => {
    const syncSection = () => setActiveSection(sectionFromHash(window.location.hash));
    window.addEventListener('hashchange', syncSection);
    return () => window.removeEventListener('hashchange', syncSection);
  }, []);

  const [clientIdInput, setClientIdInput] = useState(githubConnection.clientId || '');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [repoSearchQuery, setRepoSearchQuery] = useState('');
  const [pendingReviewRepo, setPendingReviewRepo] = useState<string | null>(null);
  const [autoReviewError, setAutoReviewError] = useState<string | null>(null);

  const [selectedProvider, setSelectedProvider] = useState<'baseten' | 'meta'>(
    providerConfig?.activeProvider || 'baseten'
  );
  const [selectedModel, setSelectedModel] = useState<string>(
    providerConfig?.activeModel || 'meta-models/muse-spark-open-1026'
  );
  const [basetenKeyInput, setBasetenKeyInput] = useState('');
  const [metaKeyInput, setMetaKeyInput] = useState('');
  const [savingProvider, setSavingProvider] = useState(false);
  const restoreSection = (section: SettingsSection) => {
    setActiveSection(section);
    window.location.hash = `settings-${section}`;
  };

  const handleSelectProviderSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSavingProvider(true);
    router.post(
      '/settings/providers/select',
      { provider: selectedProvider, model: selectedModel },
      { onFinish: () => { setSavingProvider(false); restoreSection('provider'); } }
    );
  };

  const handleSaveKey = (provider: 'baseten' | 'meta', rawKey: string, clearFn: () => void) => {
    setSavingProvider(true);
    router.post(
      '/settings/providers/key',
      { provider, api_key: rawKey },
      {
        onSuccess: () => clearFn(),
        onFinish: () => { setSavingProvider(false); restoreSection('provider'); },
      }
    );
  };

  const handleRemoveKey = (provider: 'baseten' | 'meta') => {
    setSavingProvider(true);
    router.post(
      '/settings/providers/key/remove',
      { provider },
      { onFinish: () => { setSavingProvider(false); restoreSection('provider'); } }
    );
  };

  const handleDisconnect = () => {
    if (confirm('Disconnect GitHub account? Active session tokens and cached repositories will be cleared.')) {
      setIsSubmitting(true);
      router.post('/github/device/disconnect', {}, {
        onFinish: () => setIsSubmitting(false),
      });
    }
  };

  const handleSelectRepo = (repoName: string) => {
    router.post('/github/repositories/select', { repository: repoName });
  };

  const [savingBudget, setSavingBudget] = useState(false);
  const handleSaveAutopilotBudget = (maxAttempts: string, maxDollars: string) => {
    setSavingBudget(true);
    router.post('/settings/autopilot-budget', { maxAttempts, maxDollars }, {
      onFinish: () => { setSavingBudget(false); restoreSection('usage'); },
    });
  };

  const handleAutoReview = async (repository: string, enabled: boolean) => {
    setPendingReviewRepo(repository);
    setAutoReviewError(null);
    try {
      const response = await fetch('/repositories/auto-review/toggle', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': String(props.csrf_token || ''),
        },
        body: JSON.stringify({ repository, enabled }),
      });
      const result = await response.json();
      if (!response.ok || result.success !== true) {
        throw new Error(typeof result.error === 'string' ? result.error : 'Could not update automatic reviews.');
      }
      router.reload({ only: ['autoReview'], onFinish: () => setPendingReviewRepo(null) });
    } catch (error) {
      setAutoReviewError(error instanceof Error ? error.message : 'Could not update automatic reviews.');
      setPendingReviewRepo(null);
    }
  };

  const repositories = githubConnection.repositories || [];
  const filteredRepositories = repositories.filter((r) =>
    r.fullName.toLowerCase().includes(repoSearchQuery.toLowerCase().trim())
  );

  return (
    <AppLayout activeNav="settings">
      <Head title="Settings · ProofPatcher" />

      <div className="space-y-6 w-full max-w-4xl mx-auto">
        {/* Top Header */}
        <div className="border-b border-slate-200 dark:border-slate-800 pb-4">
          <h1 className="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Settings</h1>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Manage your GitHub connection, usage and appearance.
          </p>
        </div>

        {/* Alerts */}
        {(statusMessage || githubConnection.statusMessage) && (
          <div className="p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-xs text-emerald-800 flex items-center gap-2 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-300">
            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 dark:text-emerald-400" />
            <span>{statusMessage || githubConnection.statusMessage}</span>
          </div>
        )}

        {(errorMessage || githubConnection.errorMessage) && (
          <div className="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-800 flex items-center gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 dark:text-rose-400" />
            <span>{errorMessage || githubConnection.errorMessage}</span>
          </div>
        )}

        {/* Keep hash links so saved links and browser navigation select the same section. */}
        <nav aria-label="Settings sections" className="sticky top-0 z-10 -mx-1 flex gap-4 overflow-x-auto border-b border-[var(--border)] bg-[var(--bg)] px-1 py-2 text-xs font-semibold">
          {SETTINGS_SECTIONS.map((section) => (
            <a key={section} href={`#settings-${section}`} aria-current={activeSection === section ? 'page' : undefined} className={`shrink-0 hover:underline ${activeSection === section ? 'text-[var(--accent-text)] underline underline-offset-4' : 'text-[var(--fg-muted)]'}`}>
              {{ github: 'GitHub', review: 'Code review', provider: 'Provider', autopilot: 'Autopilot', comments: 'Inline comments', usage: 'Usage', general: 'General' }[section]}
            </a>
          ))}
        </nav>

        <section id="settings-review" hidden={activeSection !== 'review'} className="scroll-mt-16 space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Repositories</h2>
                  <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Choose a repository to inspect.
                  </p>
                </div>
                <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-[11px] font-medium text-slate-700 dark:text-slate-300">
                  <FolderGit2 className="w-3 h-3 text-slate-500 dark:text-slate-400" />
                  <span>{githubConnection.isConnected ? 'GitHub' : 'Not Connected'}</span>
                </div>
              </div>

              {githubConnection.isConnected ? (
                <div className="space-y-3">
                  <div className="relative">
                    <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                      type="text"
                      placeholder="Search repositories"
                      aria-label="Search repositories"
                      value={repoSearchQuery}
                      onChange={(e) => setRepoSearchQuery(e.target.value)}
                      className="w-full pl-8 pr-3 py-2 text-xs rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-accent-600 shadow-2xs"
                    />
                  </div>

                  <div className="max-h-48 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800 border border-slate-200 dark:border-slate-800 rounded-lg">
                    {filteredRepositories.map((repo) => {
                      const isSelected = githubConnection.selectedRepo === repo.fullName;
                      return (
                        <div
                          key={repo.fullName}
                          onClick={() => handleSelectRepo(repo.fullName)}
                          className={`p-3 text-xs flex items-center justify-between cursor-pointer transition ${
                            isSelected
                              ? 'bg-accent-50/70 dark:bg-accent-950/40 text-accent-900 dark:text-accent-300 font-semibold'
                              : 'hover:bg-slate-50 dark:hover:bg-slate-800/50 text-slate-700 dark:text-slate-300'
                          }`}
                        >
                          <div className="flex items-center gap-2">
                            <FolderGit2 className="w-3.5 h-3.5 text-slate-400" />
                            <span>{repo.fullName}</span>
                            {repo.isPrivate && (
                              <span className="text-[10px] px-1.5 py-0.2 rounded bg-amber-50 text-amber-800 border border-amber-200 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-300">
                                Private
                              </span>
                            )}
                          </div>
                          {isSelected && <Check className="w-3.5 h-3.5 text-accent-700 dark:text-accent-400" />}
                        </div>
                      );
                    })}
                  </div>
                </div>
              ) : (
                <p className="text-xs text-slate-500 py-3 text-center bg-slate-50 dark:bg-slate-900/50 rounded-lg border border-dashed border-slate-300 dark:border-slate-700 dark:text-slate-400">
                  Connect GitHub to inspect repositories.
                </p>
              )}
            </div>

        </section>

        <section id="settings-autopilot" hidden={activeSection !== 'autopilot'} className="scroll-mt-16 space-y-6">
            <AutoReviewPanel connected={githubConnection.isConnected} repositories={repositories} autoReview={autoReview}
              pendingRepo={pendingReviewRepo} error={autoReviewError} onToggle={handleAutoReview}
              onRefresh={() => router.reload({ only: ['autoReview'] })} />

            <div id="settings-maintainer" className="scroll-mt-16">
            <MaintainerAutopilotPanel connected={githubConnection.isConnected} login={githubConnection.user?.login ?? null}
              repositories={repositories} csrf={String(props.csrf_token || '')} />
            </div>
        </section>

        <section id="settings-comments" hidden={activeSection !== 'comments'} className="scroll-mt-16">
          <AutomaticCommentsPanel connected={githubConnection.isConnected} login={githubConnection.user?.login ?? null}
            repositories={repositories} csrf={String(props.csrf_token || '')} />
        </section>

            {providerConfig && (
              <section
                id="settings-provider"
                hidden={activeSection !== 'provider'}
                aria-labelledby="muse-provider-config-heading"
                className="scroll-mt-16 bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-5 shadow-xs"
              >
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-3">
                  <div>
                    <h2 id="muse-provider-config-heading" className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                      Model provider
                    </h2>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                      Choose where public Muse requests are sent. Keys stay in private local storage.
                    </p>
                  </div>
                  <span
                    className={`px-2.5 py-1 rounded-full text-[11px] font-semibold border ${
                      providerConfig.activeKeyAvailable
                        ? 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-800'
                        : 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-800'
                    }`}
                  >
                    Active: {providerConfig.activeLabel} ({providerConfig.activeModel}){' '}
                    {providerConfig.activeKeyAvailable ? '· Key ready' : '· Key missing'}
                  </span>
                </div>

                {/* Active Provider & Model Selector */}
                <form onSubmit={handleSelectProviderSubmit} className="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                  <div>
                    <label htmlFor="active-provider-select" className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                      Active Inference Provider
                    </label>
                    <select
                      id="active-provider-select"
                      value={selectedProvider}
                      onChange={(e) => {
                        const nextProv = e.target.value as 'baseten' | 'meta';
                        setSelectedProvider(nextProv);
                        setSelectedModel(providerConfig.providers[nextProv]?.defaultModel || '');
                      }}
                      className="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-xs text-slate-900 dark:text-slate-100"
                    >
                      <option value="baseten">Baseten Inference API</option>
                      <option value="meta">Meta Model API (Standard Mode)</option>
                    </select>
                  </div>

                  <div>
                    <label htmlFor="active-model-select" className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                      Muse Model ID
                    </label>
                    <select
                      id="active-model-select"
                      value={selectedModel}
                      onChange={(e) => setSelectedModel(e.target.value)}
                      className="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2 text-xs font-mono text-slate-900 dark:text-slate-100"
                    >
                      {(providerConfig.providers[selectedProvider]?.models || []).map((m) => (
                        <option key={m} value={m}>
                          {m}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <button
                      type="submit"
                      disabled={savingProvider}
                      className="w-full px-4 py-2 rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-xs font-semibold hover:bg-slate-800 dark:hover:bg-slate-200 transition disabled:opacity-50"
                    >
                      Apply Provider &amp; Model
                    </button>
                  </div>
                </form>

                <div className="text-[11px] font-mono text-slate-500 dark:text-slate-400">
                  Target Endpoint: {providerConfig.providers[selectedProvider]?.endpoint}
                </div>

                {/* Encrypted API Key Forms for Baseten and Meta Model API */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-slate-100 dark:border-slate-800">
                  {(['baseten', 'meta'] as const).map((provId) => {
                    const info = providerConfig.providers[provId];
                    if (!info) return null;
                    const keyVal = provId === 'baseten' ? basetenKeyInput : metaKeyInput;
                    const setKeyVal = provId === 'baseten' ? setBasetenKeyInput : setMetaKeyInput;
                    const sourceBadge =
                      info.source === 'settings_encrypted'
                        ? 'Configured (Encrypted Local Store)'
                        : info.source === 'env'
                          ? 'Configured (Environment Variable)'
                          : info.source === 'config_file'
                            ? 'Configured (Owner-Only Key File)'
                            : 'Not Configured';

                    return (
                      <div
                        key={provId}
                        className="rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/50 p-3.5 space-y-2.5 text-xs"
                      >
                        <div className="flex items-center justify-between gap-2">
                          <span className="font-semibold text-slate-900 dark:text-slate-100">{info.label}</span>
                          <span
                            className={`px-2 py-0.5 rounded text-[10px] font-semibold ${
                              info.configured
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                : 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-400'
                            }`}
                          >
                            {sourceBadge}
                          </span>
                        </div>
                        <div className="text-[11px] font-mono text-slate-500 truncate dark:text-slate-400">{info.endpoint}</div>
                        <div className="flex items-center gap-2">
                          <input
                            type="password"
                            autoComplete="new-password"
                            aria-label={`${info.label} API key`}
                            value={keyVal}
                            onChange={(e) => setKeyVal(e.target.value)}
                            placeholder={info.configured ? '•••••••••••••••• (Enter new key to replace)' : 'Paste API key (min 16 chars)'}
                            className="flex-1 rounded-md border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs font-mono text-slate-900 dark:text-slate-100"
                          />
                          <button
                            type="button"
                            disabled={savingProvider || keyVal.trim().length < 16}
                            onClick={() => handleSaveKey(provId, keyVal, () => setKeyVal(''))}
                            className="px-3 py-1.5 rounded-md bg-accent-700 hover:bg-accent-800 text-white font-semibold text-xs transition disabled:opacity-50 shrink-0"
                          >
                            {info.configured ? 'Replace Key' : 'Save Key'}
                          </button>
                          {info.canRemove && (
                            <button
                              type="button"
                              disabled={savingProvider}
                              onClick={() => handleRemoveKey(provId)}
                              className="px-2.5 py-1.5 rounded-md border border-rose-300 dark:border-rose-800 text-rose-700 dark:text-rose-300 hover:bg-rose-50 text-xs font-medium transition shrink-0"
                            >
                              Remove
                            </button>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>

                <div className="rounded-lg bg-slate-50 dark:bg-slate-900/70 border border-slate-200/80 dark:border-slate-800 p-3 text-[11px] text-slate-600 dark:text-slate-400 space-y-1">
                  <p>
                    Saving or switching a provider makes no model request. Contributor Mode is disabled; the provider's data policies still apply.
                  </p>
                  <p>{providerConfig.modalNote}</p>
                </div>
              </section>
            )}
        <section id="settings-usage" hidden={activeSection !== 'usage'} className="scroll-mt-16 space-y-6">
          <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-[#141821]">
            <h2 className="text-sm font-semibold">Usage</h2>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Model usage on this machine. There is no local spending cap; your provider quota is the limit. Full history is on the Telemetry page.</p>
            {budget && (
              <>
              <div className="mt-4 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                <p className="text-slate-500 dark:text-slate-400">{budgetStatusLine(budget)}</p>
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Local estimates are not provider balances or billing limits. {sharedBudgetNote}</p>
              </div>
              <AutopilotBudgetCard
                caps={autopilotBudget ?? null}
                spentAttempts={budget.attempts}
                spentDollars={(budget.reservedMicros ?? 0) / 1000000}
                onSave={handleSaveAutopilotBudget}
                saving={savingBudget}
              />
              </>
            )}
          </div>
        </section>

        <section id="settings-github" hidden={activeSection !== 'github'} className="scroll-mt-16 space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                <div className="flex items-center gap-2">
                  <Key className="w-4 h-4 text-accent-700 dark:text-accent-400" />
                  <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">GitHub Connection</h2>
                </div>
                {githubConnection.isConnected && (
                  <span className="text-xs px-2 py-0.5 rounded font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400">
                    Connected
                  </span>
                )}
              </div>

              {githubConnection.isConnected && githubConnection.user ? (
                <div className="space-y-4">
                  <div className="flex items-center justify-between p-4 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                    <div className="flex items-center gap-3">
                      {githubConnection.user.avatarUrl ? (
                        <img
                          src={githubConnection.user.avatarUrl}
                          alt={`@${githubConnection.user.login}`}
                          className="w-10 h-10 rounded-full border border-slate-300 dark:border-slate-700"
                        />
                      ) : (
                        <div className="w-10 h-10 rounded-full bg-accent-600 text-white font-bold flex items-center justify-center">
                          {githubConnection.user.login.slice(0, 2).toUpperCase()}
                        </div>
                      )}
                      <div>
                        <div className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                          @{githubConnection.user.login}
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                          {repositories.length} repository {repositories.length === 1 ? 'access granted' : 'accesses granted'}
                        </p>
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={handleDisconnect}
                      disabled={isSubmitting}
                      className="px-3 py-1.5 text-xs font-semibold rounded-md border border-rose-300 text-rose-700 hover:bg-rose-50 transition dark:text-rose-400"
                    >
                      Disconnect Account
                    </button>
                  </div>
                </div>
              ) : (
                <div className="space-y-4">
                  <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                    Sign in to load your repositories, issues and pull requests. GitHub will ask you to authorize ProofPatcher, then bring you back here.
                  </p>
                  {!githubConnection.isConfigured && (
                    <div className="space-y-2">
                      <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                        GitHub App Client ID
                      </label>
                      <input
                        type="text"
                        value={clientIdInput}
                        onChange={(e) => setClientIdInput(e.target.value)}
                        placeholder="Enter client ID"
                        className="w-full px-3 py-2 text-xs border border-slate-300 dark:border-slate-700 rounded-md font-mono"
                      />
                    </div>
                  )}
                  <SignInWithGitHubButton redirectTo="/settings" clientId={clientIdInput} />
                </div>
              )}
            </div>
        </section>

        <section id="settings-general" hidden={activeSection !== 'general'} className="scroll-mt-16 space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-3 shadow-xs">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                  <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Appearance &amp; Theme</h2>
                  <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Choose Light, Dark, or follow your operating system preference.
                  </p>
                </div>
                <ThemeToggle compact={false} />
              </div>
            </div>

            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <div>
                <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                  Verification
                </h2>
                <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                  Checks are currently limited to the included test case. Private repository content stays local.
                </p>
              </div>

            </div>
        </section>
      </div>
    </AppLayout>
  );
}
