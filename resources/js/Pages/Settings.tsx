import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';
import { copyText } from '../Components/ui/copyText';
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
import { AutopilotBudgetCard } from '../Components/settings/AutopilotBudgetCard';

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

  const [activeTab, setActiveTab] = useState<'code-review' | 'usage' | 'github' | 'general'>('code-review');
  const [usageSubTab, setUsageSubTab] = useState<'overview' | 'analytics'>('overview');

  const [clientIdInput, setClientIdInput] = useState(githubConnection.clientId || '');
  const [copiedCode, setCopiedCode] = useState(false);
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

  const handleSelectProviderSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSavingProvider(true);
    router.post(
      '/settings/providers/select',
      { provider: selectedProvider, model: selectedModel },
      { onFinish: () => setSavingProvider(false) }
    );
  };

  const handleSaveKey = (provider: 'baseten' | 'meta', rawKey: string, clearFn: () => void) => {
    setSavingProvider(true);
    router.post(
      '/settings/providers/key',
      { provider, api_key: rawKey },
      {
        onSuccess: () => clearFn(),
        onFinish: () => setSavingProvider(false),
      }
    );
  };

  const handleRemoveKey = (provider: 'baseten' | 'meta') => {
    setSavingProvider(true);
    router.post(
      '/settings/providers/key/remove',
      { provider },
      { onFinish: () => setSavingProvider(false) }
    );
  };

  const copyUserCode = () => {
    if (githubConnection.deviceFlow?.userCode) {
      void copyText(githubConnection.deviceFlow.userCode).then((ok) => {
        if (!ok) return;
        setCopiedCode(true);
        setTimeout(() => setCopiedCode(false), 2000);
      });
    }
  };

  const handleInitiate = (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    router.post('/github/device/initiate', { client_id: clientIdInput }, {
      onFinish: () => setIsSubmitting(false),
    });
  };

  const handlePoll = () => {
    setIsSubmitting(true);
    router.post('/github/device/poll', {}, {
      onFinish: () => setIsSubmitting(false),
    });
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
      onFinish: () => setSavingBudget(false),
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

        {/* Navigation Tabs */}
        <div className="border-b border-slate-200 dark:border-slate-800">
          <nav className="flex space-x-6 sm:space-x-8 overflow-x-auto">
            <button
              type="button"
              onClick={() => setActiveTab('code-review')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'code-review'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <GitPullRequest className="w-3.5 h-3.5" />
              <span>Code Review</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('usage')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'usage'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <BarChart3 className="w-3.5 h-3.5" />
              <span>Usage &amp; Limits</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('github')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'github'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <Key className="w-3.5 h-3.5" />
              <span>GitHub Connection</span>
              {githubConnection.isConnected && (
                <span className="w-2 h-2 rounded-full bg-emerald-500" />
              )}
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('general')}
              className={`py-2.5 px-1 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition whitespace-nowrap ${
                activeTab === 'general'
                  ? 'border-accent-700 text-accent-900 dark:text-accent-400'
                  : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 dark:text-slate-400'
              }`}
            >
              <Sliders className="w-3.5 h-3.5" />
              <span>General &amp; Sandbox</span>
            </button>
          </nav>
        </div>

        {/* ═══════════════════════ TAB 1: CODE REVIEW ═══════════════════════ */}
        {activeTab === 'code-review' && (
          <div className="space-y-6">
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

            <AutoReviewPanel connected={githubConnection.isConnected} repositories={repositories} autoReview={autoReview}
              pendingRepo={pendingReviewRepo} error={autoReviewError} onToggle={handleAutoReview}
              onRefresh={() => router.reload({ only: ['autoReview'] })} />

            {providerConfig && (
              <section
                aria-labelledby="muse-provider-config-heading"
                className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-5 shadow-xs"
              >
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-3">
                  <div>
                    <h2 id="muse-provider-config-heading" className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                      Muse Model Provider &amp; Encrypted API Keys
                    </h2>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                      Choose which provider and model handles public Muse requests. Keys are encrypted locally with AES-256-GCM (0600 owner-only) and never returned to the browser.
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
                      className="w-full px-4 py-2 rounded-lg bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 text-xs font-semibold hover:bg-slate-800 transition disabled:opacity-50"
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
                    <strong>Privacy &amp; Cost Safeguards:</strong> Saving or switching a provider never makes automatic validation requests. Meta Contributor Mode models are blocked so code is never shared for model training.
                  </p>
                  <p>{providerConfig.modalNote}</p>
                </div>
              </section>
            )}
          </div>
        )}

        {/* ═══════════════════════ TAB 2: USAGE & LIMITS ═══════════════════════ */}
        {activeTab === 'usage' && (
          <div className="space-y-6">
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setUsageSubTab('overview')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                  usageSubTab === 'overview'
                    ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-xs'
                    : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 border border-slate-200 dark:border-slate-700 dark:hover:bg-slate-700'
                }`}
              >
                Overview
              </button>
              <button
                type="button"
                onClick={() => setUsageSubTab('analytics')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                  usageSubTab === 'analytics'
                    ? 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 shadow-xs'
                    : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 border border-slate-200 dark:border-slate-700 dark:hover:bg-slate-700'
                }`}
              >
                Analytics
              </button>
            </div>

            {usageSubTab === 'overview' ? (
              <div className="space-y-6">
                <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-[#141821]">
                  <h2 className="text-sm font-semibold">Provider balances</h2>
                  <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Unavailable here. Check your provider dashboard for balances and billing.</p>
                  {budget && (
                    <>
                    <div className="mt-5 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                      <h3 className="font-medium">Local request budget</h3>
                      <p className="mt-1 text-slate-500 dark:text-slate-400">{budget.attempts} of {budget.maxAttempts} attempts · ${(budget.remainingMicros / 1000000).toFixed(2)} estimated remaining</p>
                      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Local estimates are not provider balances or billing limits.</p>
                    </div>
                    <AutopilotBudgetCard
                      caps={autopilotBudget ?? null}
                      spentAttempts={budget.attempts}
                      spentDollars={(budget.limitMicros - budget.remainingMicros) / 1000000}
                      onSave={handleSaveAutopilotBudget}
                      saving={savingBudget}
                    />
                    </>
                  )}
                </div>
              </div>
            ) : (
              <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
                <h2 className="text-sm font-semibold">Usage history</h2>
                <p className="text-sm text-slate-500 dark:text-slate-400">Provider usage is not available in ProofPatcher. Check your provider dashboard.</p>
              </div>
            )}
          </div>
        )}

        {/* ═══════════════════════ TAB 3: GITHUB CONNECTION ═══════════════════════ */}
        {activeTab === 'github' && (
          <div className="space-y-6">
            <div className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-4 shadow-xs">
              <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                <div className="flex items-center gap-2">
                  <Key className="w-4 h-4 text-accent-700 dark:text-accent-400" />
                  <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">GitHub OAuth Device Flow (RFC 8628)</h2>
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
                  <form onSubmit={handleInitiate} className="space-y-3">
                    <div>
                      <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-0.5">
                        GitHub OAuth App Client ID
                      </label>
                      <p className="text-[11px] text-slate-500 dark:text-slate-400 mb-2 leading-relaxed">
                        Connects ProofPatcher to your repositories via Device Flow (RFC 8628). Find your Client ID under{' '}
                        <a
                          href="https://github.com/settings/developers"
                          target="_blank"
                          rel="noreferrer"
                          className="text-accent-600 underline font-medium"
                        >
                          GitHub &gt; Settings &gt; Developer settings &gt; OAuth Apps
                        </a>
                        , or keep the pre-configured default.
                      </p>
                      <input
                        type="text"
                        value={clientIdInput}
                        onChange={(e) => setClientIdInput(e.target.value)}
                        placeholder="e.g. Iv1.8f2a1b9c3d4e5f60"
                        className="w-full px-3 py-2 text-xs border border-slate-300 dark:border-slate-700 rounded-md font-mono"
                        required
                      />
                    </div>
                    <button
                      type="submit"
                      disabled={isSubmitting || !clientIdInput.trim()}
                      className="px-4 py-2 text-xs font-semibold rounded-md text-white bg-slate-900 hover:bg-slate-800 transition"
                    >
                      {isSubmitting ? 'Starting...' : 'Connect GitHub App'}
                    </button>
                  </form>

                  {githubConnection.deviceFlow && (
                    <div className="p-4 rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 space-y-3">
                      <div className="text-xs font-semibold text-slate-800 dark:text-slate-200">
                        Verification Code: <span className="font-mono text-accent-700 font-bold dark:text-accent-300">{githubConnection.deviceFlow.userCode}</span>
                      </div>
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={copyUserCode}
                          className="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white text-slate-700 dark:bg-[#141821] dark:border-slate-700 dark:text-slate-300"
                        >
                          {copiedCode ? 'Copied' : 'Copy Code'}
                        </button>
                        <a
                          href={githubConnection.deviceFlow.verificationUri}
                          target="_blank"
                          rel="noreferrer"
                          className="px-3 py-1 text-xs font-semibold rounded text-white bg-accent-700"
                        >
                          Authorize on GitHub →
                        </a>
                        <button
                          type="button"
                          onClick={handlePoll}
                          disabled={isSubmitting}
                          className="px-3 py-1 text-xs font-medium rounded border border-slate-300 bg-white dark:bg-[#141821] dark:border-slate-700"
                        >
                          Check Status
                        </button>
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        )}

        {/* ═══════════════════════ TAB 4: GENERAL & SANDBOX ═══════════════════════ */}
        {activeTab === 'general' && (
          <div className="space-y-6">
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
          </div>
        )}
      </div>
    </AppLayout>
  );
}
