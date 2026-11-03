export interface GateRecord {
  baselineReproduced?: boolean;
  protectedTestsPassed?: boolean;
  outsideMaskPreserved?: boolean;
  authorityIntact?: boolean;
}

export interface ReportData {
  id: string;
  decision: string;
  baseCommit?: string;
  gates: GateRecord;
  proposal?: {
    file: string;
    find?: string;
    replace?: string;
  };
  proposalEvidence?: { origin?: string; model?: string; sourceRunId?: string } | null;
  visual?: {
    outsideChangedPixels: number;
  };
}

export interface ReceiptData {
  sha256: string;
  externallyAnchored: boolean;
}

export interface BudgetStatus {
  reservedMicros: number;
  limitMicros: number;
  attempts: number;
  maxAttempts: number;
  canRequest: boolean;
}

export interface HistoryItem {
  id: string;
  decision: string;
  kind?: string;
  hasVisual?: boolean;
  proposal?: {
    file: string;
  };
  targetFile?: string;
}

export interface ChecksProps {
  runId: string;
  fixture: string;
  report?: ReportData | null;
  receipt?: ReceiptData | null;
  receiptError?: string | null;
  history: Record<string, HistoryItem>;
  budgetStatus?: BudgetStatus | null;
  baselineReady: boolean;
  proposal?: any;
  hasVisual?: boolean;
  latestVisualRunId?: string | null;
  error?: string | null;
}
