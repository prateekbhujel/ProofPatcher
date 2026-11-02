import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { BrandMark } from '../ui/BrandMark';
import { SignInWithGitHubButton } from '../ui/SignInWithGitHubButton';
import { GitHubConnectionData } from './types';

interface DisconnectedOnboardingViewProps {
  error?: string | null;
  githubConnection?: GitHubConnectionData | null;
  /** In-app page to return to after authorizing. */
  redirectTo?: string;
}

/**
 * Connect screen: one framed panel in a spotlight field — the only door
 * into GitHub-backed work. Local task surfaces stay reachable from here.
 */
export function DisconnectedOnboardingView({ error, githubConnection, redirectTo = '/issues' }: DisconnectedOnboardingViewProps) {
  const [clientIdInput, setClientIdInput] = useState(githubConnection?.clientId || '');
  return (
    <>
      <Head title="Connect GitHub" />
      <div
        className="flex min-h-screen items-center justify-center px-4 py-12"
        style={{ background: 'radial-gradient(640px 420px at 50% 42%, var(--accent-soft) 0%, var(--bg) 72%)' }}
      >
        <div className="w-full max-w-md border border-[var(--border)] bg-[var(--bg-raised)] p-6 shadow-lg">
          <div className="flex items-center gap-2.5">
            <BrandMark size={30} />
            <div>
              <p className="text-sm font-bold leading-tight">proofpatcher</p>
            </div>
          </div>
          <h1 className="mt-5 text-base font-semibold">Connect GitHub</h1>
          <p className="mt-1 text-xs leading-relaxed text-[var(--fg-muted)]">
            Sign in to load your repositories, issues and pull requests. Nothing runs on GitHub until you connect.
          </p>
          {error && <p role="alert" className="mt-4 border border-rose-300 bg-rose-50 p-2.5 text-xs text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">{error}</p>}
          <div className="mt-5 space-y-3">
            {!githubConnection?.isConfigured && (
              <div className="space-y-1.5">
                <p className="text-xs text-[var(--fg-muted)]">This installation needs a GitHub App client ID.</p>
                <label htmlFor="onboarding-client-id" className="block text-xs font-semibold">GitHub App client ID</label>
                <input id="onboarding-client-id" value={clientIdInput} onChange={(e) => setClientIdInput(e.target.value)} placeholder="Enter client ID" autoComplete="off" className="w-full border border-[var(--border-strong)] bg-transparent px-2.5 py-2 text-xs" />
              </div>
            )}
            <SignInWithGitHubButton redirectTo={redirectTo} clientId={clientIdInput} className="w-full" />
            <p className="text-[11px] text-[var(--fg-muted)]">GitHub will ask you to authorize ProofPatcher, then bring you back here.</p>
          </div>
          <div className="mt-5 flex items-center justify-between border-t border-[var(--border)] pt-3 text-xs">
            <Link href="/settings" className="text-[var(--fg-muted)] hover:text-[var(--fg)] hover:underline">settings</Link>
            <Link href="/" className="text-[var(--fg-muted)] hover:text-[var(--fg)] hover:underline">console</Link>
          </div>
        </div>
      </div>
    </>
  );
}
