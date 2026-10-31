import React from 'react';
import { Link } from '@inertiajs/react';
import { Search, ImageIcon } from 'lucide-react';
import { HistoryItem } from './types';

interface ChecksHistoryTabProps {
  filteredHistory: HistoryItem[];
  historyListLength: number;
  runId: string;
  historyQuery: string;
  setHistoryQuery: (query: string) => void;
  paginatedHistory: HistoryItem[];
  historyTotalPages: number;
  safeHistoryPage: number;
  historyPageSize: number;
  setHistoryPage: (updater: (p: number) => number) => void;
}

export function ChecksHistoryTab({
  filteredHistory,
  historyListLength,
  runId,
  historyQuery,
  setHistoryQuery,
  paginatedHistory,
  historyTotalPages,
  safeHistoryPage,
  historyPageSize,
  setHistoryPage,
}: ChecksHistoryTabProps) {
  return (
    <div className="bg-white rounded-lg border border-slate-200 shadow-xs overflow-hidden">
      <div className="px-5 py-3.5 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Run History</h2>
          <p className="text-xs text-slate-500">{historyListLength} runs recorded</p>
        </div>
        <div className="relative w-full sm:w-60">
          <input
            type="text"
            value={historyQuery}
            onChange={(e) => setHistoryQuery(e.target.value)}
            placeholder="Filter runs..."
            className="w-full text-xs rounded-md border border-slate-300 pl-8 pr-3 py-1.5 text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900"
          />
          <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2" />
        </div>
      </div>

      {filteredHistory.length === 0 ? (
        <p className="p-8 text-center text-xs text-slate-500">No matching runs found.</p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-slate-50/80 border-b border-slate-200 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                  <th className="py-2.5 px-5">Run Identifier</th>
                  <th className="py-2.5 px-5">Target Fixture</th>
                  <th className="py-2.5 px-5">Decision</th>
                  <th className="py-2.5 px-5 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 text-xs">
                {paginatedHistory.map((item) => (
                  <tr
                    key={item.id}
                    className={`hover:bg-slate-50/60 transition-colors ${
                      item.id === runId ? 'bg-slate-50 font-medium' : ''
                    }`}
                  >
                    <td className="py-3 px-5 font-mono text-[11px] text-slate-800">{item.id}</td>
                    <td className="py-3 px-5 text-slate-600">
                      <div className="flex items-center gap-2">
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] bg-slate-100 text-slate-700 border border-slate-200">
                          {(item.proposal?.file || item.targetFile || 'checkout.html').split('/').pop()}
                        </span>
                        {item.hasVisual ? (
                          <span className="inline-flex items-center gap-1 text-[10px] font-medium text-accent-700 bg-accent-50 px-1.5 py-0.5 rounded border border-accent-200">
                            <ImageIcon className="w-2.5 h-2.5" />
                            <span>Visual UI</span>
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 text-[10px] font-medium text-slate-600 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">
                            <span>Backend</span>
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="py-3 px-5">
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold ${
                          item.decision === 'VERIFIED'
                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                            : item.decision === 'FAILED'
                            ? 'bg-rose-50 text-rose-700 border border-rose-200'
                            : 'bg-amber-50 text-amber-700 border border-amber-200'
                        }`}
                      >
                        {item.decision}
                      </span>
                    </td>
                    <td className="py-3 px-5 text-right">
                      <Link
                        href={`/checks?run=${encodeURIComponent(item.id)}`}
                        className="inline-flex items-center gap-1 text-xs font-medium text-slate-900 hover:underline"
                      >
                        <span>Inspect</span>
                        <span>→</span>
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {historyTotalPages > 1 && (
            <div className="px-5 py-3 border-t border-slate-200 flex items-center justify-between text-xs text-slate-600 bg-slate-50/30">
              <span className="text-[11px] text-slate-500">
                Showing {(safeHistoryPage - 1) * historyPageSize + 1} to{' '}
                {Math.min(safeHistoryPage * historyPageSize, filteredHistory.length)} of {filteredHistory.length} runs
              </span>
              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={() => setHistoryPage((p) => Math.max(1, p - 1))}
                  disabled={safeHistoryPage === 1}
                  className="px-2 py-1 rounded border border-slate-300 bg-white text-slate-700 disabled:opacity-40 text-xs shadow-2xs"
                >
                  Prev
                </button>
                <span className="text-xs font-medium px-2">
                  Page {safeHistoryPage} of {historyTotalPages}
                </span>
                <button
                  type="button"
                  onClick={() => setHistoryPage((p) => Math.min(historyTotalPages, p + 1))}
                  disabled={safeHistoryPage === historyTotalPages}
                  className="px-2 py-1 rounded border border-slate-300 bg-white text-slate-700 disabled:opacity-40 text-xs shadow-2xs"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
