import React from 'react';
import { ChevronDown, Play, Sparkles } from 'lucide-react';
import { BudgetStatus } from './types';
import { budgetDollars, reviewUsageLine } from '../ui/budgetCopy';

interface ChecksWorkspaceTabProps {
  data: {
    fixture: string;
    find: string;
    replace: string;
    action: string;
    source_run: string;
  };
  setData: (fieldOrUpdater: any, value?: any) => void;
  handleVerify: (e: React.FormEvent) => void;
  handlePropose: (e: React.FormEvent) => void;
  processing: boolean;
  proposal?: any;
  baselineReady: boolean;
  budgetStatus?: BudgetStatus | null;
}

export function ChecksWorkspaceTab({
  data,
  setData,
  handleVerify,
  handlePropose,
  processing,
  proposal,
  baselineReady,
  budgetStatus,
}: ChecksWorkspaceTabProps) {
  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
      <div className="lg:col-span-2 bg-white rounded-lg border border-slate-200 shadow-xs p-5 space-y-4 dark:bg-[#141821] dark:border-slate-800">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-slate-800">
          <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-400">
            Candidate CSS Patch
          </span>
          <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:border-slate-800 dark:text-slate-400">
            {proposal ? 'MUSE SUGGESTION' : 'MANUAL PATCH'}
          </span>
        </div>

        <form onSubmit={handleVerify} className="space-y-4">
          <div>
            <label htmlFor="fixture" className="block text-xs font-semibold text-slate-700 mb-1.5 dark:text-slate-300">
              Target Fixture
            </label>
            <div className="relative">
              <select
                id="fixture"
                value={data.fixture}
                onChange={(e) => {
                  const fix = e.target.value;
                  setData('fixture', fix);
                  if (fix === 'public/fixtures/modal-overflow.html') {
                    setData((d: any) => ({ ...d, fixture: fix, find: 'max-height: 0px;', replace: 'max-height: 60px;' }));
                  } else {
                    setData((d: any) => ({ ...d, fixture: fix, find: 'pointer-events: auto;', replace: 'pointer-events: none;' }));
                  }
                }}
                className="w-full appearance-none rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 pr-10 text-xs font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-accent-600 focus:border-transparent shadow-2xs transition cursor-pointer dark:bg-[#141821] dark:border-slate-700 dark:text-slate-200"
              >
                <option value="public/fixtures/checkout.html">Northline Checkout (Stacking Context Trap)</option>
                <option value="public/fixtures/occlusion-b.html">Lumen Checkout (Pseudo-element Occlusion)</option>
                <option value="public/fixtures/modal-overflow.html">Aura Checkout (Responsive Modal Overflow)</option>
              </select>
              <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400">
                <ChevronDown className="w-4 h-4" />
              </div>
            </div>
          </div>

          <div>
            <label htmlFor="find" className="block text-xs font-medium text-slate-700 mb-1 dark:text-slate-300">
              Find Existing CSS
            </label>
            <textarea
              id="find"
              rows={3}
              required
              spellCheck={false}
              value={data.find}
              onChange={(e) => setData('find', e.target.value)}
              className="w-full rounded-md border border-slate-300 p-2.5 font-mono text-xs text-slate-900 bg-slate-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900 shadow-2xs dark:bg-slate-900/50 dark:border-slate-700 dark:text-slate-100"
            />
          </div>

          <div>
            <label htmlFor="replace" className="block text-xs font-medium text-slate-700 mb-1 dark:text-slate-300">
              Replace With Candidate CSS
            </label>
            <textarea
              id="replace"
              rows={3}
              required
              spellCheck={false}
              value={data.replace}
              onChange={(e) => setData('replace', e.target.value)}
              className="w-full rounded-md border border-slate-300 p-2.5 font-mono text-xs text-slate-900 bg-slate-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900 shadow-2xs dark:bg-slate-900/50 dark:border-slate-700 dark:text-slate-100"
            />
            <div className="flex flex-wrap items-center gap-2 pt-2 text-xs text-slate-500 dark:text-slate-400">
              <span className="font-medium">Presets:</span>
              <button
                type="button"
                onClick={() => {
                  if (data.fixture === 'public/fixtures/modal-overflow.html') {
                    setData((d: any) => ({ ...d, find: 'max-height: 0px;', replace: 'max-height: 60px;' }));
                  } else {
                    setData((d: any) => ({ ...d, find: 'pointer-events: auto;', replace: 'pointer-events: none;' }));
                  }
                }}
                className="px-2 py-0.5 rounded bg-slate-100 text-slate-800 hover:bg-slate-200 font-medium border border-slate-200 transition text-[11px] dark:bg-slate-800 dark:border-slate-800 dark:text-slate-200"
              >
                Load Valid Repair (4/4 Pass)
              </button>
              <button
                type="button"
                onClick={() => {
                  setData((d: any) => ({ ...d, find: '#promo-banner { position: absolute;', replace: '#promo-banner { display: none; position: absolute;' }));
                }}
                className="px-2 py-0.5 rounded bg-rose-50 text-rose-700 hover:bg-rose-100 font-medium border border-rose-200 transition text-[11px] dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-400"
              >
                Load Damaging Shortcut (Fails Guard)
              </button>
            </div>
          </div>

          <div className="flex flex-col sm:flex-row gap-2.5 pt-1">
            <button
              type="submit"
              disabled={processing}
              className="flex-1 inline-flex items-center justify-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-md text-white bg-accent-700 hover:bg-accent-800 shadow-xs transition disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:ring-offset-2"
            >
              <Play className="w-3.5 h-3.5" />
              <span>{processing ? 'Evaluating...' : 'Run Verification'}</span>
            </button>

            <button
              type="button"
              onClick={handlePropose}
              disabled={processing || !baselineReady || !(budgetStatus?.canRequest ?? false)}
              className="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 text-xs font-medium rounded-md border border-slate-300 text-slate-700 bg-white hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed shadow-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-[#141821] dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            >
              <Sparkles className="w-3.5 h-3.5 text-slate-600 dark:text-slate-400" />
              <span>Generate with Muse</span>
            </button>
          </div>
        </form>
      </div>

      {/* Model ledger card */}
      <div className="bg-white rounded-lg border border-slate-200 shadow-xs p-5 space-y-3 dark:bg-[#141821] dark:border-slate-800">
        <div className="flex items-center justify-between border-b border-slate-100 pb-2.5 dark:border-slate-800">
          <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-400">
            Model Ledger
          </span>
          <span className="text-[10px] font-mono text-slate-400">SQLite</span>
        </div>

        <div className="space-y-2.5 text-xs text-slate-600 dark:text-slate-400">
          {budgetStatus ? (
            <>
              <div className="flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                <span className="text-slate-500 dark:text-slate-400">Held for model calls</span>
                <span className="font-mono font-semibold text-slate-900 dark:text-slate-100">
                  {budgetDollars(budgetStatus.reservedMicros)}
                </span>
              </div>
              <div className="flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                <span className="text-slate-500 dark:text-slate-400">Local spending limit</span>
                <span className="font-mono text-slate-900 dark:text-slate-100">
                  {budgetDollars(budgetStatus.limitMicros)}
                </span>
              </div>
              <div className="flex items-center justify-between py-1">
                <span className="text-slate-500 dark:text-slate-400">Model requests</span>
                <span className="font-medium text-slate-900 dark:text-slate-100">
                  {reviewUsageLine(budgetStatus.attempts, budgetStatus.maxAttempts)}
                </span>
              </div>
            </>
          ) : (
            <p className="text-slate-500 dark:text-slate-400">Budget unavailable right now.</p>
          )}

          <div className="pt-1.5 text-slate-500 text-[11px] leading-relaxed dark:text-slate-400">
            This local estimate is not a provider billing limit. Check your provider account for actual usage.
          </div>
        </div>
      </div>
    </div>
  );
}
