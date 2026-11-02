export interface GitHubRepo {
  id: number;
  name: string;
  fullName: string;
  owner: string;
  isPrivate: boolean;
  defaultBranch: string;
  htmlUrl: string;
}

export interface GitHubConnection {
  isConfigured: boolean;
  clientId: string | null;
  isConnected: boolean;
  user: {
    login: string;
    name?: string;
    avatarUrl?: string;
    htmlUrl: string;
  } | null;
  repositories: GitHubRepo[];
  repositoriesStatus?: { truncated?: boolean; errors?: string[] } | null;
  selectedRepo: string | null;
  deviceFlow: {
    userCode: string;
    verificationUri: string;
    expiresAt: number;
    interval: number;
  } | null;
  statusMessage?: string | null;
  errorMessage?: string | null;
}

export interface BudgetStatus {
  limitMicros: number;
  maxAttempts: number;
  reservedMicros: number;
  attempts: number;
  remainingMicros: number;
  canRequest: boolean;
}

export interface SettingsPolicy {
  maxDiffLines?: number;
  antiSlop?: boolean;
  requireTests?: boolean;
  [key: string]: any;
}

export interface ProviderStatusItem {
  id: 'baseten' | 'meta';
  label: string;
  endpoint: string;
  models: string[];
  defaultModel: string;
  configured: boolean;
  source: 'settings_encrypted' | 'env' | 'config_file' | 'none';
  canRemove: boolean;
  updatedAt?: string | null;
}

export interface ProviderConfig {
  activeProvider: 'baseten' | 'meta';
  activeLabel: string;
  activeModel: string;
  activeEndpoint: string;
  activeKeyAvailable: boolean;
  providers: Record<'baseten' | 'meta', ProviderStatusItem>;
  modalNote: string;
}

export interface AutoReviewStats {
  totalReviews: number;
  completed: number;
  failed: number;
  skippedPrivate: number;
  skippedBudget: number;
}

export interface AutoReviewRecentResult {
  id: number;
  repository: string;
  pull_number: number;
  head_sha?: string | null;
  status: string;
  summary: string | null;
  risk_level: string | null;
  recommendation: string | null;
  model: string | null;
  published_status: string | null;
  reviewed_at: string | null;
  created_at: string | null;
}

export interface AutopilotBudgetCaps {
  maxAttempts: number | null;
  maxReservedDollars: number | null;
}

export interface SettingsProps {
  githubConnection: GitHubConnection;
  budget: BudgetStatus | null;
  policy: SettingsPolicy;
  providerConfig?: ProviderConfig | null;
  autopilotBudget?: AutopilotBudgetCaps | null;
  statusMessage?: string | null;
  errorMessage?: string | null;
  autoReview?: {
    worker: { isLive: boolean; status: string; message?: string };
    settings: Record<string, { repository: string; enabled: boolean }> | [];
    stats?: AutoReviewStats;
    recentResults?: AutoReviewRecentResult[];
    publicOnly: boolean;
  } | null;
}
