import React, { useCallback, useEffect, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { MenuBar } from '../Components/os/MenuBar';
import { OsDesktop } from '../Components/os/OsDesktop';
import {
  KernelFinding,
  KernelNextStep,
  KernelStage,
  KernelVerification,
} from '../Components/os/DesktopWindows';

interface KernelProps {
  connected: boolean;
  user: { login: string } | null;
  reposCount: number;
  selectedRepo: string | null;
  openIssues: number;
  openPulls: number;
  provider: { label: string; model: string; keyReady: boolean };
  budget: { attempts: number; maxAttempts: number; remainingMicros: number; canRequest: boolean } | null;
  autopilotCaps?: { maxAttempts: number | null; maxReservedDollars: number | null } | null;
  worker: { isLive: boolean; status: string };
  autoStats: { totalReviews: number; completed: number; failed: number; skippedPrivate: number; skippedBudget: number };
  recentFindings: KernelFinding[];
  nextStep: KernelNextStep;
  stages: KernelStage[];
  lastVerification: KernelVerification | null;
  publishedPr: { prNumber: number | null; prUrl: string | null; repository: string | null } | null;
}

/**
 * The Kernel home is the ProofPatcher desktop: the shared menu bar over the
 * window manager. Everything actionable lives in windows, the rail, the
 * start menu, or the taskbar.
 */
export default function Kernel(props: KernelProps) {
  const { connected, user, nextStep } = props;
  const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const refreshDesktop = useCallback(() => {
    if (refreshTimer.current !== null) clearTimeout(refreshTimer.current);
    refreshTimer.current = setTimeout(() => {
      refreshTimer.current = null;
      router.reload({ only: [
        'connected', 'user', 'reposCount', 'selectedRepo', 'openIssues', 'openPulls',
        'provider', 'budget', 'autopilotCaps', 'worker', 'autoStats', 'recentFindings',
        'nextStep', 'stages', 'lastVerification', 'publishedPr',
      ] });
    }, 100);
  }, []);
  useEffect(() => () => {
    if (refreshTimer.current !== null) clearTimeout(refreshTimer.current);
  }, []);

  return (
    <div className="min-h-screen bg-[var(--bg)] text-[var(--fg)]">
      <Head title="Kernel · ProofPatcher" />
      <MenuBar
        center={`→ ${nextStep.title} — ${nextStep.detail}`}
        connected={connected}
        userLogin={user?.login ?? null}
      />
      <main>
        <OsDesktop
          connected={props.connected}
          userLogin={user?.login ?? null}
          reposCount={props.reposCount}
          selectedRepo={props.selectedRepo}
          openIssues={props.openIssues}
          openPulls={props.openPulls}
          provider={props.provider}
          budget={props.budget}
          autopilotCaps={props.autopilotCaps}
          worker={props.worker}
          autoStats={props.autoStats}
          recentFindings={props.recentFindings}
          nextStep={props.nextStep}
          stages={props.stages}
          lastVerification={props.lastVerification}
          publishedPr={props.publishedPr}
          onStatusChanged={refreshDesktop}
        />
      </main>
    </div>
  );
}
