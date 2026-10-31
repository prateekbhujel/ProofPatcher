import React, { useState } from 'react';

interface Props {
  caps: { maxAttempts: number | null; maxReservedDollars: number | null } | null;
  spentAttempts: number;
  spentDollars: number;
  onSave: (maxAttempts: string, maxDollars: string) => void;
  saving: boolean;
}

export function AutopilotBudgetCard({ caps, spentAttempts, spentDollars, onSave, saving }: Props) {
  const [attempts, setAttempts] = useState(caps?.maxAttempts != null ? String(caps.maxAttempts) : '');
  const [dollars, setDollars] = useState(caps?.maxReservedDollars != null ? String(caps.maxReservedDollars) : '');

  return (
    <div className="mt-5 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
      <h3 className="font-medium">Autopilot spending cap</h3>
      <p className="mt-1 text-slate-500 dark:text-slate-400">
        Spent so far: {spentAttempts} attempts · ${spentDollars.toFixed(2)} reserved.
        {caps == null || (caps.maxAttempts == null && caps.maxReservedDollars == null)
          ? ' No cap set: autopilot reviews are bounded only by the local request budget.'
          : ` Capped at${caps.maxAttempts != null ? ` ${caps.maxAttempts} attempts` : ''}${caps.maxAttempts != null && caps.maxReservedDollars != null ? ' and' : ''}${caps.maxReservedDollars != null ? ` $${caps.maxReservedDollars.toFixed(2)}` : ''}.`}
      </p>
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-xs text-slate-500 dark:text-slate-400">
          Max attempts
          <input
            type="number"
            min={1}
            inputMode="numeric"
            value={attempts}
            onChange={(e) => setAttempts(e.target.value)}
            placeholder="No cap"
            aria-label="Autopilot maximum attempts"
            className="mt-1 block w-36 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-sm text-slate-900 dark:text-slate-100"
          />
        </label>
        <label className="text-xs text-slate-500 dark:text-slate-400">
          Max spend (USD)
          <input
            type="number"
            min={0}
            step="0.01"
            inputMode="decimal"
            value={dollars}
            onChange={(e) => setDollars(e.target.value)}
            placeholder="No cap"
            aria-label="Autopilot maximum spend in dollars"
            className="mt-1 block w-36 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-sm text-slate-900 dark:text-slate-100"
          />
        </label>
        <div className="flex items-center gap-2 pb-0.5">
          <button
            type="button"
            disabled={saving}
            onClick={() => onSave(attempts.trim(), dollars.trim())}
            className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 hover:bg-slate-800 transition disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save cap'}
          </button>
          <button
            type="button"
            disabled={saving}
            onClick={() => { setAttempts(''); setDollars(''); onSave('', ''); }}
            className="px-3 py-1.5 rounded-lg text-xs font-semibold border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition disabled:opacity-50"
          >
            Clear
          </button>
        </div>
      </div>
      <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Caps stop automatic reviews when reached. They never raise the local request budget.</p>
    </div>
  );
}
