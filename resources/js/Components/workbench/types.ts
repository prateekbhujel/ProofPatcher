import {
  LocalTaskCard,
  CardPreview,
  CardProposalPreview,
  CardProposalResult,
} from '../TaskCardSection';

export interface GitHubRepo {
  fullName: string;
  defaultBranch?: string;
  isPrivate: boolean;
  installedRepo?: boolean;
}

export interface IssueTriage {
  issueNumber: number;
  classification: 'ISSUE_REVIEW';
  badge: string;
  category: 'invalid' | 'needs-repro' | 'needs-text-repro' | 'bug';
  severity: 'emerald' | 'amber' | 'rose';
  headline: string;
  suggestedAction: string;
  suggestedComment: string;
  flags: string[];
}

export interface GitHubIssueItem {
  number: number;
  state?: string;
  title: string;
  body: string;
  user: string;
  htmlUrl: string;
  createdAt: string;
  labels: string[];
  isPrivate: boolean;
  triage?: IssueTriage;
}

export interface GitHubPullItem {
  number: number;
  state: string;
  isMerged?: boolean;
  title: string;
  head: string;
  base: string;
  headSha: string;
  baseSha: string;
  user: string;
  isPrivate: boolean;
}

export interface GitHubConnectionData {
  isConfigured: boolean;
  clientId: string | null;
  isConnected: boolean;
  user: {
    login: string;
    name?: string;
    avatarUrl?: string;
  } | null;
  /** Full list is no longer shipped to Issues; pickers page through the search endpoint. */
  repositories?: GitHubRepo[];
  repositoryCount?: number;
  selectedRepo: string | null;
  dedicatedIssueNumber?: number | null;
  repoIssues: GitHubIssueItem[];
  repoPulls: GitHubPullItem[];
  repoListIncomplete: boolean;
  deviceFlow: {
    userCode: string;
    verificationUri: string;
    expiresAt: number;
    interval: number;
  } | null;
}

export interface IssueContext {
  isSynthetic?: boolean;
  provenance?: string;
  repository: string;
  issueNumber: number;
  issueUrl: string | null;
  title: string;
  body: string;
  commit: string | null;
  path: string;
  source: string;
  sourceSha256: string;
}

export interface CompletenessCheck {
  key: string;
  label: string;
  passed: boolean;
  detail: string;
}

export interface IssueCompleteness {
  score: number;
  verdict: 'complete' | 'needs_info' | 'empty';
  checks: CompletenessCheck[];
  missing: string[];
  questions: string[];
}

export interface Preview {
  destination: string;
  body: Record<string, unknown>;
  digest: string;
  estimatedMicros: number;
  approvalToken?: string;
}

export interface CandidatePreview {
  status: string;
  source: string;
  sha256: string;
  applied: boolean;
  verified: boolean;
  verificationRunId?: string;
}

export interface Suggestion {
  path: string;
  commit: string | null;
  find: string;
  replace: string;
  explanation: string;
  model: string | null;
  usage?: Record<string, number> | null;
  isOfflineExample?: boolean;
  isSynthetic?: boolean;
  provenance?: string;
  safetyNotice?: string;
  candidate?: CandidatePreview;
}

export interface Draft {
  comment: string;
  savedAt: string;
  readOnlyPolicy: string;
}

export interface VerificationRun {
  exitCode: number;
  logSha256: string;
  summary: string;
}

export interface VerificationGates {
  baselineReproduced: boolean;
  negativeControlCaught: boolean;
  candidatePassed: boolean;
  candidateStaticSafetyPassed: boolean;
  allowlistIntegrityIntact: boolean;
}

export interface VerificationReceipt {
  id: string;
  sha256: string;
  integrity: string;
  externallyAnchored: boolean;
  manifest: Record<string, unknown>;
}

export interface BackendSandboxGap {
  status: string;
  boundary: string;
  isolationLevel: string;
  remainingGap: string;
}

export interface VerificationReport {
  id: string;
  kind: string;
  createdAt: string;
  decision: 'VERIFIED' | 'FAILED' | 'INCONCLUSIVE';
  isSimulated?: boolean;
  baseCommit: string;
  isSynthetic?: boolean;
  provenance?: string;
  targetFile: string;
  baseSha256: string;
  candidateSha256: string;
  patchSha256: string;
  gates: VerificationGates;
  runs: {
    baseline: VerificationRun;
    negative: VerificationRun;
    candidate: VerificationRun;
  };
  receipt: VerificationReceipt;
  evidenceDirectory: string;
  backendSandboxGap: BackendSandboxGap;
}

export interface WorkbenchTaskData {
  id: number;
  key: string;
  repository: string;
  issueNumber: number;
  title: string;
  targetPath: string;
  status: string;
  lastAction: string;
  updatedAt: string;
}

export interface BudgetData {
  canRequest: boolean;
  attempts: number;
  maxAttempts: number;
  remainingMicros?: number;
  limitMicros?: number;
}

export interface RepoMemoryState {
  defaultPath: string;
  testCommand: string;
  branchRule: string;
  lastVerifiedReceipt: string | null;
}
