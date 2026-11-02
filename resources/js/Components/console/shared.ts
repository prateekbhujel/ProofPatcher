/** Shared kernel-home types, formerly part of the desktop windows module. */

export interface KernelStage {
  key: 'intake' | 'propose' | 'verify' | 'publish';
  title: string;
  detail: string;
  state: 'done' | 'active' | 'todo';
  href: string;
}

export interface KernelNextStep {
  key: string;
  title: string;
  detail: string;
  href: string;
  cta: string;
}

export interface KernelFinding {
  id: number;
  repository: string;
  pull_number: number;
  head_sha?: string | null;
  status: string;
  summary: string | null;
  risk_level: string | null;
  recommendation: string | null;
  reviewed_at: string | null;
}

export interface KernelVerification {
  id: string;
  decision: string;
  isSimulated: boolean | null;
  receiptSha256: string | null;
  repository: string | null;
}

/** localStorage key for a note drafted into the task composer. */
export const COMPOSER_DRAFT_KEY = 'proofpatch.composerDraft.v1';

export interface ComposerDraft {
  title: string;
  description: string;
}
