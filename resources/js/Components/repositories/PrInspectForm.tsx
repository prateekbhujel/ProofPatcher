import React from 'react';
import { FileText } from 'lucide-react';

interface PrInspectFormProps {
  url: string;
  setUrl: (val: string) => void;
  onSubmit: (e: React.FormEvent) => void;
  processing: boolean;
  isHero?: boolean;
}

export function PrInspectForm({
  url,
  setUrl,
  onSubmit,
  processing,
  isHero = false,
}: PrInspectFormProps) {
  if (isHero) {
    return (
      <div className="bg-white rounded-lg border border-slate-200 shadow-xs p-6 max-w-xl mx-auto space-y-5 dark:bg-[#141821] dark:border-slate-800">
        <div className="space-y-1.5 text-center">
          <div className="w-10 h-10 mx-auto rounded-lg bg-accent-50 text-accent-700 flex items-center justify-center dark:bg-accent-950/40 dark:text-accent-300">
            <FileText className="w-5 h-5" />
          </div>
          <h1 className="text-lg font-bold tracking-tight text-slate-900 dark:text-slate-100">Inspect Pull Request</h1>
          <p className="text-xs text-slate-500 max-w-sm mx-auto dark:text-slate-400">
            Enter a GitHub pull request URL or short handle to capture changed files and check runs. The sample verifier is separate.
          </p>
        </div>

        <form onSubmit={onSubmit} className="space-y-3">
          <div className="flex flex-col sm:flex-row gap-2">
            <input
              type="text"
              required
              placeholder="owner/repo#123 or https://github.com/owner/repo/pull/123"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-600 focus:border-accent-600 shadow-2xs dark:border-slate-700 dark:text-slate-100"
            />
            <button
              type="submit"
              disabled={processing}
              className="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-xs font-medium rounded-md text-white bg-accent-700 hover:bg-accent-800 shadow-xs transition disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:ring-offset-2"
            >
              <span>{processing ? 'Loading...' : 'Inspect PR'}</span>
            </button>
          </div>
        </form>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-lg border border-slate-200 shadow-xs p-5 space-y-4 dark:bg-[#141821] dark:border-slate-800">
      <div>
        <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Inspect Another Pull Request</h2>
        <p className="text-xs text-slate-500 dark:text-slate-400">Enter a short handle or complete GitHub URL.</p>
      </div>

      <form onSubmit={onSubmit} className="space-y-4">
        <div className="flex flex-col sm:flex-row gap-2.5">
          <input
            type="text"
            required
            placeholder="owner/repo#123 or https://github.com/owner/repo/pull/123"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-600 focus:border-accent-600 shadow-2xs dark:border-slate-700 dark:text-slate-100"
          />
          <button
            type="submit"
            disabled={processing}
            className="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-xs font-medium rounded-md text-white bg-accent-700 hover:bg-accent-800 shadow-xs transition disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:ring-offset-2"
          >
            <span>{processing ? 'Loading...' : 'Inspect PR'}</span>
          </button>
        </div>
      </form>
    </div>
  );
}
