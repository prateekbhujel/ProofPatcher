import React from 'react';
import { Check, Copy } from 'lucide-react';
import { SnapshotData } from './types';

interface PrReceiptTabProps {
  snapshot?: SnapshotData | null;
  copiedDigest: boolean;
  copyText: (text: string, type: any) => void;
}

export function PrReceiptTab({ snapshot, copiedDigest, copyText }: PrReceiptTabProps) {
  return (
    <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-6 space-y-4">
      <div>
        <h2 className="text-base font-semibold text-slate-900">Cryptographic Evidence Receipt</h2>
        <p className="text-xs text-slate-500">
          SHA-256 digest of this saved snapshot. It is not independently authenticated.
        </p>
      </div>

      {snapshot ? (
        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-700">Snapshot Digest</span>
            <button
              type="button"
              onClick={() => copyText(snapshot.sha256, 'digest')}
              className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-md bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 shadow-xs transition"
            >
              {copiedDigest ? <Check className="w-3 h-3 text-emerald-600" /> : <Copy className="w-3 h-3 text-slate-500" />}
              <span>{copiedDigest ? 'Copied' : 'Copy Hash'}</span>
            </button>
          </div>
          <div className="font-mono text-xs text-accent-900 bg-white p-3 rounded-lg border border-slate-200 break-all select-all">
            {snapshot.sha256}
          </div>
          <div className="text-xs text-slate-500">Captured: {snapshot.capturedAt}</div>
        </div>
      ) : (
        <p className="text-xs text-slate-500">Snapshot digest unavailable for this session.</p>
      )}
    </div>
  );
}
