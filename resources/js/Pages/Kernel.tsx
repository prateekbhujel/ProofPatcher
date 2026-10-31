import React from 'react';
import { Head } from '@inertiajs/react';
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

  return (
    <div className="min-h-screen bg-[#070b14] text-slate-100">
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
        />
      </main>
    </div>
  );
}
