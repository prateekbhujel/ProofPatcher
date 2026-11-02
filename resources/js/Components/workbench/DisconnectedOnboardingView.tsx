import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '../AppLayout';
import { BrandMark } from '../ui/BrandMark';
import { SignInWithGitHubButton } from '../ui/SignInWithGitHubButton';
import { GitHubConnectionData } from './types';

interface DisconnectedOnboardingViewProps {
  error?: string | null;
  githubConnection?: GitHubConnectionData | null;
  /** In-app page to return to after authorizing. */
  redirectTo?: string;
}

export function DisconnectedOnboardingView({ error, githubConnection, redirectTo = '/issues' }: DisconnectedOnboardingViewProps) {
  const [clientIdInput, setClientIdInput] = useState(githubConnection?.clientId || '');
  return (
    <AppLayout activeNav="issues">
      <Head title="Connect GitHub · ProofPatcher" />
      <div className="flex min-h-screen items-center justify-center px-4 py-12">
        <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm dark:border-slate-800 dark:bg-[#141821]">
          <BrandMark size={40} />
          <h1 className="mt-5 text-xl font-semibold tracking-tight">Connect GitHub</h1>
          <p className="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Connect your account to see your repositories, issues and pull requests.</p>
          {error && <p role="alert" className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-200">{error}</p>}
          <div className="mt-6 space-y-4">
            {!githubConnection?.isConfigured && (
              <div className="space-y-2">
                <p className="text-sm text-slate-500 dark:text-slate-400">This installation needs a GitHub App client ID.</p>
                <label htmlFor="onboarding-client-id" className="block text-sm font-medium">GitHub App client ID</label>
                <input id="onboarding-client-id" value={clientIdInput} onChange={(e) => setClientIdInput(e.target.value)} placeholder="Enter client ID" autoComplete="off" className="w-full rounded-lg border border-slate-300 bg-transparent px-3 py-2 text-sm dark:border-slate-700" />
              </div>
            )}
            <SignInWithGitHubButton redirectTo={redirectTo} clientId={clientIdInput} className="w-full" />
            <p className="text-xs text-slate-500 dark:text-slate-400">GitHub will ask you to authorize ProofPatcher, then bring you back here.</p>
          </div>
          <Link href="/settings" className="mt-5 inline-block text-sm text-slate-500 hover:text-accent-700 dark:hover:text-accent-300 dark:text-slate-400">Settings</Link>
        </div>
      </div>
    </AppLayout>
  );
}
