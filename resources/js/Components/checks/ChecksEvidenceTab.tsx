import React from 'react';
import { Check, X, Copy } from 'lucide-react';
import { ReportData, ReceiptData } from './types';

interface ChecksEvidenceTabProps {
  report?: ReportData | null;
  receipt?: ReceiptData | null;
  receiptError?: string | null;
  runId: string;
  copiedReceipt: boolean;
  copyReceipt: () => void;
  gatesList: Array<{ key: string; label: string }>;
}

export function ChecksEvidenceTab({
  report,
  receipt,
  receiptError,
  runId,
  copiedReceipt,
  copyReceipt,
  gatesList,
}: ChecksEvidenceTabProps) {
  if (!report) {
    return (
      <div className="bg-white rounded-lg border border-slate-200 shadow-xs p-10 text-center space-y-2 dark:bg-[#141821] dark:border-slate-800">
        <p className="text-xs text-slate-600 dark:text-slate-400">No verification run selected.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* 4 Gates Grid */}
      <div className="bg-white rounded-lg border border-slate-200 shadow-xs p-5 space-y-4 dark:bg-[#141821] dark:border-slate-800">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-slate-800">
          <div>
            <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">4 verification checks</h2>
            <p className="text-xs text-slate-500 dark:text-slate-400">Results from the included checkout example.</p>
            {report.proposalEvidence?.origin === 'model' && typeof report.proposalEvidence.model === 'string' && (
              <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">Recorded proposal: {report.proposalEvidence.model}</p>
            )}
          </div>
          <span className="text-xs font-mono font-bold text-slate-700 dark:text-slate-300">{report.decision}</span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
          {gatesList.map((g) => {
            const state = (report.gates as any)[g.key];
            return (
              <div
                key={g.key}
                className={`p-3.5 rounded-lg border space-y-2 ${
                  state === true
                    ? 'bg-emerald-50/40 border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800'
                    : state === false
                    ? 'bg-rose-50/40 border-rose-200 dark:bg-rose-950/40 dark:border-rose-800'
                    : 'bg-slate-50 border-slate-200 dark:bg-slate-900 dark:border-slate-800'
                }`}
              >
                <div className="flex items-center justify-between">
                  <span
                    className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold ${
                      state === true
                        ? 'bg-emerald-600 text-white'
                        : state === false
                        ? 'bg-rose-600 text-white'
                        : 'bg-slate-300 text-slate-700 dark:bg-slate-600 dark:text-slate-300'
                    }`}
                  >
                    {state === true ? <Check className="w-3 h-3" /> : state === false ? <X className="w-3 h-3" /> : '–'}
                  </span>
                  <span
                    className={`text-[10px] font-bold uppercase tracking-wider ${
                      state === true
                        ? 'text-emerald-700 dark:text-emerald-400'
                        : state === false
                        ? 'text-rose-700 dark:text-rose-400'
                        : 'text-slate-500 dark:text-slate-400'
                    }`}
                  >
                    {state === true ? 'PASSED' : state === false ? 'FAILED' : 'INCONCLUSIVE'}
                  </span>
                </div>
                <div className="font-medium text-xs text-slate-900 dark:text-slate-100">{g.label}</div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Evidence Receipt */}
      <div className="bg-white rounded-lg border border-slate-200 shadow-xs p-5 space-y-4 dark:bg-[#141821] dark:border-slate-800">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-slate-800">
          <div>
            <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Evidence Receipt</h2>
            <p className="text-xs text-slate-500 dark:text-slate-400">Deterministic SHA-256 integrity receipt for this run.</p>
          </div>
          <span
            className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold ${
              receipt
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400'
                : 'bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-400'
            }`}
          >
            {receipt ? 'INTEGRITY PASSED' : 'INTEGRITY UNAVAILABLE'}
          </span>
        </div>

        {receipt ? (
          <div className="p-4 rounded-lg bg-slate-50 border border-slate-200 space-y-2.5 dark:bg-slate-900 dark:border-slate-800">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                SHA-256 Receipt Digest
              </span>
              <button
                type="button"
                onClick={copyReceipt}
                className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-md bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 shadow-xs transition dark:bg-[#141821] dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              >
                {copiedReceipt ? <Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" /> : <Copy className="w-3 h-3 text-slate-500 dark:text-slate-400" />}
                <span>{copiedReceipt ? 'Copied' : 'Copy Hash'}</span>
              </button>
            </div>
            <div className="font-mono text-xs text-slate-900 bg-white p-2.5 rounded border border-slate-200 break-all select-all dark:bg-[#141821] dark:border-slate-800 dark:text-slate-100">
              {receipt.sha256}
            </div>
            <div className="text-[11px] text-slate-500 flex items-center gap-2 pt-0.5 dark:text-slate-400">
              <span>Run: <code className="font-mono text-slate-700 dark:text-slate-300">{runId}</code></span>
              <span>·</span>
              <span>Anchored: {receipt.externallyAnchored ? 'Yes' : 'No'}</span>
            </div>
          </div>
        ) : receiptError ? (
          <div role="alert" className="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
            {receiptError}
          </div>
        ) : null}
      </div>
    </div>
  );
}
