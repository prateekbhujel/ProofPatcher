import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Copy, ExternalLink, GitBranch } from 'lucide-react';
import { AppLayout } from '../AppLayout';
import { BrandMark } from '../ui/BrandMark';
import { GitHubConnectionData } from './types';

interface DisconnectedOnboardingViewProps {
  error?: string | null;
  deviceClientIdInput: string;
  setDeviceClientIdInput: (value: string) => void;
  isSubmittingDevice: boolean;
  handleInitiateDeviceFlow: (e?: any) => void;
  handlePollDeviceFlow: (e?: any) => void;
  copyDeviceCode: (code: string) => void;
  copiedDeviceCode: boolean;
  githubConnection?: GitHubConnectionData | null;
}

export function DisconnectedOnboardingView({ error, deviceClientIdInput, setDeviceClientIdInput, isSubmittingDevice, handleInitiateDeviceFlow, handlePollDeviceFlow, copyDeviceCode, copiedDeviceCode, githubConnection }: DisconnectedOnboardingViewProps) {
  const device = githubConnection?.deviceFlow;
  return (
    <AppLayout activeNav="issues">
      <Head title="Connect GitHub · ProofPatcher" />
      <div className="flex min-h-screen items-center justify-center px-4 py-12">
        <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm dark:border-slate-800 dark:bg-[#141821]">
          <BrandMark size={40} />
          <h1 className="mt-5 text-xl font-semibold tracking-tight">Connect GitHub</h1>
          <p className="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400">Connect your account to see your repositories, issues and pull requests.</p>
          {error && <p role="alert" className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-200">{error}</p>}
          {device ? (
            <div className="mt-6 space-y-4">
              <p className="text-sm text-slate-600 dark:text-slate-400">Enter this code on GitHub, then return here.</p>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded-lg bg-slate-100 px-4 py-3 text-center text-lg font-semibold tracking-widest dark:bg-slate-800">{device.userCode}</code>
                <button type="button" onClick={() => copyDeviceCode(device.userCode)} aria-label="Copy code" className="rounded-lg border border-slate-300 p-3 dark:border-slate-700"><Copy className="h-4 w-4" /></button>
              </div>
              {copiedDeviceCode && <p className="text-xs text-slate-500">Copied</p>}
              <a href={device.verificationUri || 'https://github.com/login/device'} target="_blank" rel="noreferrer" className="flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium dark:border-slate-700">Open GitHub <ExternalLink className="h-4 w-4" /></a>
              <button type="button" disabled={isSubmittingDevice} onClick={() => handlePollDeviceFlow()} className="w-full rounded-lg bg-accent-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-accent-800 disabled:opacity-50">{isSubmittingDevice ? 'Checking…' : 'Check connection'}</button>
            </div>
          ) : (
            <form onSubmit={handleInitiateDeviceFlow} className="mt-6 space-y-4">
              {!githubConnection?.isConfigured && (
                <div className="space-y-2">
                  <p className="text-sm text-slate-500">This installation needs a GitHub App client ID.</p>
                  <label htmlFor="onboarding-client-id" className="block text-sm font-medium">GitHub App client ID</label>
                  <input id="onboarding-client-id" value={deviceClientIdInput} onChange={(e) => setDeviceClientIdInput(e.target.value)} placeholder="Enter client ID" autoComplete="off" className="w-full rounded-lg border border-slate-300 bg-transparent px-3 py-2 text-sm dark:border-slate-700" />
                </div>
              )}
              <button type="submit" disabled={isSubmittingDevice || (!githubConnection?.isConfigured && !deviceClientIdInput.trim())} className="flex w-full items-center justify-center gap-2 rounded-lg bg-accent-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-accent-800 disabled:opacity-50">
                <GitBranch className="h-4 w-4" />
                {isSubmittingDevice ? 'Connecting…' : 'Continue with GitHub'}
              </button>
              <p className="text-xs text-slate-500">You’ll enter a short code on GitHub.</p>
            </form>
          )}
          <Link href="/settings" className="mt-5 inline-block text-sm text-slate-500 hover:text-accent-700 dark:hover:text-accent-300">Settings</Link>
        </div>
      </div>
    </AppLayout>
  );
}
