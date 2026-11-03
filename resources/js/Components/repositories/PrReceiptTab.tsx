import React from 'react';
import { Check, Copy } from 'lucide-react';
import { SnapshotData } from './types';
import { formatDateTime } from '../ui/date';

interface PrReceiptTabProps {
  snapshot?: SnapshotData | null;
  copiedDigest: boolean;
  copyText: (text: string, type: any) => void;
}

export function PrReceiptTab({ snapshot, copiedDigest, copyText }: PrReceiptTabProps) {
  return (
    <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-6 space-y-4 dark:bg-[#141821] dark:border-slate-800/80">
      <div>
        <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">Cryptographic Evidence Receipt</h2>
        <p className="text-xs text-slate-500 dark:text-slate-400">
          SHA-256 digest of this saved snapshot. It is not independently authenticated.
        </p>
      </div>

      {snapshot ? (
        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-3 dark:bg-slate-900 dark:border-slate-800">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">Snapshot Digest</span>
            <button
              type="button"
              onClick={() => copyText(snapshot.sha256, 'digest')}
              className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-md bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 shadow-xs transition dark:bg-[#141821] dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              {copiedDigest ? <Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" /> : <Copy className="w-3 h-3 text-slate-500 dark:text-slate-400" />}
              <span>{copiedDigest ? 'Copied' : 'Copy Hash'}</span>
            </button>
          </div>
          <div className="font-mono text-xs text-accent-900 bg-white p-3 rounded-lg border border-slate-200 break-all select-all dark:bg-[#141821] dark:border-slate-800 dark:text-accent-300">
            {snapshot.sha256}
          </div>
          <div className="text-xs text-slate-500 dark:text-slate-400">Captured: {formatDateTime(snapshot.capturedAt)}</div>
        </div>
      ) : (
        <p className="text-xs text-slate-500 dark:text-slate-400">Snapshot digest unavailable for this session.</p>
      )}
    </div>
  );
}
