import React from 'react';
import { Layers, Shield, FileText, CheckCircle2, PlusCircle } from 'lucide-react';

interface PrTabBarProps {
  activeTab: string;
  setActiveTab: (tab: any) => void;
  triageComparisonsCount?: number;
  filesCount: number;
  reviewRiskLevel?: string;
  draftsCount: number;
  passedChecks: number;
  checksCount: number;
}

export function PrTabBar({
  activeTab,
  setActiveTab,
  triageComparisonsCount = 0,
  filesCount,
  reviewRiskLevel,
  draftsCount,
  passedChecks,
  checksCount,
}: PrTabBarProps) {
  return (
    <div className="border-b border-slate-200">
      <nav className="flex space-x-6 sm:space-x-8 overflow-x-auto">
        <button
          type="button"
          onClick={() => setActiveTab('overview')}
          className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
            activeTab === 'overview'
              ? 'border-accent-700 text-accent-900 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
          }`}
        >
          <Layers className="w-3.5 h-3.5" />
          <span>Overview</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('triage')}
          className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
            activeTab === 'triage'
              ? 'border-accent-700 text-accent-900 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
          }`}
        >
          <Shield className="w-3.5 h-3.5" />
          <span>Triage & Overlap</span>
          {triageComparisonsCount > 0 && (
            <span className="ml-1 bg-amber-100 text-amber-800 px-1.5 py-0.2 rounded text-[10px] font-semibold">
              {triageComparisonsCount}
            </span>
          )}
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('files')}
          className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
            activeTab === 'files'
              ? 'border-accent-700 text-accent-900 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
          }`}
        >
          <FileText className="w-3.5 h-3.5" />
          <span>Changed Files</span>
          <span className="ml-1 bg-slate-100 text-slate-700 px-1.5 py-0.2 rounded text-[10px] font-semibold">
            {filesCount}
          </span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('review')}
          className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
            activeTab === 'review'
              ? 'border-accent-700 text-accent-900 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
          }`}
        >
          <FileText className="w-3.5 h-3.5 text-slate-700" />
          <span>Review & Findings</span>
          {reviewRiskLevel && (
            <span
              className={`ml-1 px-1.5 py-0.2 rounded text-[10px] font-semibold ${
                reviewRiskLevel === 'low'
                  ? 'bg-emerald-100 text-emerald-800'
                  : reviewRiskLevel === 'medium'
                  ? 'bg-amber-100 text-amber-800'
                  : 'bg-rose-100 text-rose-800'
              }`}
            >
              {reviewRiskLevel} risk
            </span>
          )}
          {draftsCount > 0 && (
            <span className="ml-1 bg-slate-100 text-slate-700 px-1.5 py-0.2 rounded text-[10px] font-semibold">
              {draftsCount} draft{draftsCount === 1 ? '' : 's'}
            </span>
          )}
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('checks')}
          className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
            activeTab === 'checks'
              ? 'border-accent-700 text-accent-900 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
          }`}
        >
          <CheckCircle2 className="w-3.5 h-3.5" />
          <span>GitHub Checks</span>
          <span className="ml-1 bg-slate-100 text-slate-700 px-1.5 py-0.2 rounded text-[10px] font-semibold">
            {passedChecks}/{checksCount}
          </span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('receipt')}
          className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
            activeTab === 'receipt'
              ? 'border-accent-700 text-accent-900 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
          }`}
        >
          <Shield className="w-3.5 h-3.5" />
          <span>Receipt</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('new')}
          className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
            activeTab === 'new'
              ? 'border-accent-700 text-accent-900 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-800 font-medium'
          }`}
        >
          <PlusCircle className="w-3.5 h-3.5" />
          <span>Inspect Another</span>
        </button>
      </nav>
    </div>
  );
}
