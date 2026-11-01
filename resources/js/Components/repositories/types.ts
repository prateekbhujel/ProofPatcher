import { PatchFile, CommentDraft } from '../DiffViewer';
export type { CommentDraft };

export interface GitHubRepo {
  id: number;
  name: string;
  fullName: string;
  owner: string;
  isPrivate: boolean;
  defaultBranch: string;
  htmlUrl: string;
}

export interface GitHubUser {
  login: string;
  name: string | null;
  avatarUrl: string | null;
  htmlUrl: string;
}

export interface GitHubDeviceFlowData {
  userCode: string;
  verificationUri: string;
  expiresAt: number;
  interval: number;
}

export interface GitHubPull {
  number: number;
  title: string;
  head: string;
  base: string;
  headSha: string;
  baseSha: string;
  user: string;
  isPrivate: boolean;
}

export interface GitHubStatusMetadata {
  truncated?: boolean;
  installationsCount?: number;
  repositoriesCount?: number;
  count?: number;
  errors?: string[];
}

export interface GitHubConnectionData {
  canCapturePrivateTestRepo?: boolean;
  isConfigured: boolean;
  clientId: string | null;
  isConnected: boolean;
  user: GitHubUser | null;
  repositories: GitHubRepo[];
  repositoriesStatus?: GitHubStatusMetadata | null;
  selectedRepo: string | null;
  repoPulls: GitHubPull[];
  repoPullsStatus?: GitHubStatusMetadata | null;
  deviceFlow: GitHubDeviceFlowData | null;
  statusMessage: string | null;
  errorMessage: string | null;
}

export interface ChangedFile {
  name: string;
  additions: number;
  deletions: number;
  status?: string;
  patch?: string;
}

export interface CheckRun {
  name: string;
  status: string;
  conclusion?: string | null;
  id?: number;
  startedAt?: string | null;
  completedAt?: string | null;
  htmlUrl?: string | null;
}

export interface PullData {
  repository: string;
  number: number;
  title: string;
  state: string;
  head: string;
  base: string;
  headSha: string;
  url: string;
  isPrivate?: boolean;
  isDraft?: boolean;
  files?: ChangedFile[];
  checks?: CheckRun[];
}

export interface SnapshotData {
  id: string;
  capturedAt: string;
  sha256: string;
}

export interface TriageComparison {
  candidate: {
    repository: string;
    number: number;
    headSha: string;
    title: string;
    fileCount: number;
  };
  jaccardScore: number;
  matchPercentage: number;
  sharedFiles: string[];
  sharedFilesCount: number;
  incomplete: boolean;
  relationship: string;
  verdictBanner: string;
}

export interface TriageData {
  target: {
    repository: string;
    number: number;
    headSha: string;
    title: string;
    base?: string;
    head?: string;
    totalChanges: number;
    testPaths: string[];
    fileCount: number;
    filesTruncated: boolean;
  };
  policy: {
    mode: string;
    status: 'review' | 'unknown' | 'within_limit';
    maxDiffLines: number;
    totalChanges: number;
    diffExceeded: boolean;
    requireTestCoverage: boolean;
    coverage: 'unknown';
  };
  summary: {
    verdict: string;
    classification?: string;
    badge?: string;
    headline: string;
    detail: string;
    severity: 'red' | 'amber' | 'emerald';
    suggestedAction?: string;
    suggestedComment?: string;
    reasoning?: string;
    flags?: string[];
  };
  comparisons: TriageComparison[];
}

export interface ReviewFinding {
  path: string;
  status: 'note' | 'concern' | 'approval';
  lineHint: number | null;
  comment: string;
  commentable?: boolean;
  anchorStatus?: 'anchored' | 'hint_only' | 'stale';
  anchorMessage?: string;
  lineType?: string | null;
}

export interface ReviewResult {
  unverified: boolean;
  model: string;
  reviewedAt: string;
  inputDigest: string;
  summary: string;
  riskLevel: 'low' | 'medium' | 'high';
  recommendation: 'approve' | 'request_changes' | 'discuss';
  fileFindings: ReviewFinding[];
  usage: {
    prompt_tokens: number;
    completion_tokens: number;
  };
  source?: 'automatic' | 'manual';
  publishedStatus?: string | null;
}

export interface ReviewPreviewData {
  destination: string;
  digest: string;
  estimatedMicros: number;
  filesCount: number;
  fileNames: string[];
  body?: Record<string, any>;
  approvalToken?: string;
  isPrivate?: boolean;
}


export interface BudgetData {
  attempts: number;
  limitAttempts: number;
  reservedMicros: number;
  limitMicros: number;
  remainingMicros: number;
  canRequest: boolean;
  maxAttempts?: number;
}

export interface RepositoriesProps {
  pull?: PullData | null;
  snapshot?: SnapshotData | null;
  triage?: TriageData | null;
  error?: string | null;
  patchPreview?: {
    headSha: string;
    baseSha: string;
    isPrivate?: boolean;
    changedFiles: number;
    filesTruncated: boolean;
    files: Array<{ name: string; status: 'available' | 'missing' | 'oversize' | 'partial'; patch: string | null }>;
  } | null;
  patchPreviewError?: string | null;
  review?: ReviewResult | null;
  reviewPreview?: ReviewPreviewData | null;
  reviewError?: string | null;
  drafts?: CommentDraft[];
  commentApprovals?: Record<string, string>;
  commentPosted?: boolean;
  budget?: BudgetData | null;
  modelKeyAvailable?: boolean;
  githubConnection?: GitHubConnectionData | null;
  csrf?: string;
}
