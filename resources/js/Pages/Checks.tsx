import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';
import { copyText } from '../Components/ui/copyText';
import { VisualComparisonViewer } from '../Components/VisualComparisonViewer';
import { ChecksWorkspaceTab } from '../Components/checks/ChecksWorkspaceTab';
import { ChecksEvidenceTab } from '../Components/checks/ChecksEvidenceTab';
import { ChecksHistoryTab } from '../Components/checks/ChecksHistoryTab';
import type {
  GateRecord,
  ReportData,
  ReceiptData,
  BudgetStatus,
  HistoryItem,
  ChecksProps,
} from '../Components/checks/types';
import {
  Code2,
  ImageIcon,
  ShieldCheck,
  History,
  ArrowRight,
} from 'lucide-react';

export default function Checks({
  runId,
  fixture = 'public/fixtures/checkout.html',
  report = null,
  receipt = null,
  receiptError = null,
  history = {},
  budgetStatus = null,
  baselineReady = false,
  proposal = null,
  hasVisual = false,
  latestVisualRunId = null,
  error = null,
}: ChecksProps) {
  const [activeTab, setActiveTab] = useState<'workspace' | 'visual' | 'evidence' | 'history'>(() => {
    if (typeof window !== 'undefined') {
      const paramTab = new URLSearchParams(window.location.search).get('tab');
      if (paramTab === 'visual' || paramTab === 'evidence' || paramTab === 'history') {
        return paramTab;
      }
    }
    return 'workspace';
  });

  const [copiedReceipt, setCopiedReceipt] = useState(false);
  const [historyQuery, setHistoryQuery] = useState('');
  const [historyPage, setHistoryPage] = useState(1);
  const historyPageSize = 8;

  const { data, setData, post, processing } = useForm({
    action: 'verify',
    fixture: fixture,
    find: proposal?.patch?.find || 'pointer-events: auto;',
    replace: proposal?.patch?.replace || 'pointer-events: none;',
    source_run: runId,
  });

  const handleVerify = (e: React.FormEvent) => {
    e.preventDefault();
    data.action = 'verify';
    post('/checks');
  };

  const handlePropose = (e: React.FormEvent) => {
    e.preventDefault();
    data.action = 'propose';
    post('/checks');
  };

  const copyReceipt = () => {
    if (receipt?.sha256) {
      void copyText(receipt.sha256).then((ok) => {
        if (!ok) return;
        setCopiedReceipt(true);
        setTimeout(() => setCopiedReceipt(false), 1500);
      });
    }
  };

  const gatesList = [
    { key: 'baselineReproduced', label: 'Bug reproduced' },
    { key: 'protectedTestsPassed', label: 'Protected checks' },
    { key: 'outsideMaskPreserved', label: 'Visual guard' },
    { key: 'authorityIntact', label: 'Checks unchanged' },
  ];

  const historyList = Object.entries(history).map(([id, item]) => ({ ...item, id }));
  const filteredHistory = historyList.filter(
    (h) =>
      h.id.toLowerCase().includes(historyQuery.toLowerCase().trim()) ||
      h.decision.toLowerCase().includes(historyQuery.toLowerCase().trim())
  );
  const historyTotalPages = Math.max(1, Math.ceil(filteredHistory.length / historyPageSize));
  const safeHistoryPage = Math.min(historyPage, historyTotalPages);
  const paginatedHistory = filteredHistory.slice((safeHistoryPage - 1) * historyPageSize, safeHistoryPage * historyPageSize);

  const decision = report?.decision || 'READY';

  return (
    <AppLayout activeNav="checks">
      <Head title="Verification Workspace" />

      <div className="space-y-6">
        {/* Workspace Context Hero */}
        <div className="bg-white rounded-xl border border-slate-200 shadow-xs p-5 space-y-4 dark:bg-[#141821] dark:border-slate-800">
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div className="space-y-1">
              <div className="flex items-center gap-2 text-xs text-slate-500 font-medium dark:text-slate-400">
                <span className="px-1.5 py-0.5 rounded bg-accent-100 text-accent-800 font-mono text-[10px] font-bold uppercase tracking-wider dark:bg-accent-950/60 dark:text-accent-300">
                  Kernel Module · Chromium Sandbox
                </span>
                <span>/</span>
                <span className="font-semibold text-slate-900 dark:text-slate-100">
                  {fixture.includes('occlusion-b') ? 'Lumen Checkout' : fixture.includes('modal-overflow') ? 'Aura Checkout' : 'Northline Checkout'}
                </span>
              </div>
              <h1 className="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Visual &amp; CSS Sandbox Verification</h1>
              <p className="text-xs text-slate-600 max-w-2xl dark:text-slate-400">
                4-gate headless Chromium visual verifier for UI/CSS patches (Baseline Reproduction, Protected E2E Tests, Outside-Mask Pixel Guard, and Test Authority Integrity). For backend PHP/AST 5-Gate repository issue verification, use{' '}
                <Link href="/issues" className="font-semibold text-accent-700 hover:underline dark:text-accent-300">
                  Issues &amp; Tasks
                </Link>
                .
              </p>
            </div>

            <div className="flex items-center gap-3">
              <span
                className={`inline-flex items-center px-2.5 py-1 rounded text-xs font-semibold ${
                  decision === 'VERIFIED'
                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400'
                    : decision === 'FAILED'
                    ? 'bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-400'
                    : decision === 'INCONCLUSIVE'
                    ? 'bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-400'
                    : 'bg-slate-100 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:border-slate-800 dark:text-slate-300'
                }`}
              >
                <span
                  className={`w-1.5 h-1.5 rounded-full mr-1.5 ${
                    decision === 'VERIFIED'
                      ? 'bg-emerald-500'
                      : decision === 'FAILED'
                      ? 'bg-rose-500'
                      : decision === 'INCONCLUSIVE'
                      ? 'bg-amber-500'
                      : 'bg-slate-400'
                  }`}
                />
                {decision}
              </span>

              <span className="font-mono text-xs text-slate-500 dark:text-slate-400">
                Run ID: <code className="bg-slate-100 text-slate-800 px-1.5 py-0.5 rounded border border-slate-200 text-[11px] dark:bg-slate-800 dark:border-slate-800 dark:text-slate-200">{runId.slice(0, 16)}...</code>
              </span>
            </div>
          </div>
        </div>

        {/* Error notification */}
        {error && (
          <div role="alert" className="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
            {error}
          </div>
        )}

        {/* Tab Bar */}
        <div className="border-b border-slate-200 dark:border-slate-800">
          <nav className="flex space-x-6 sm:space-x-8 overflow-x-auto">
            <button
              type="button"
              onClick={() => setActiveTab('workspace')}
              className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
                activeTab === 'workspace'
                  ? 'border-slate-900 text-slate-900 font-semibold dark:text-slate-100'
                  : 'border-transparent text-slate-500 hover:text-slate-800 font-medium dark:text-slate-400'
              }`}
            >
              <Code2 className="w-3.5 h-3.5" />
              <span>Workspace & Patch</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('visual')}
              className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
                activeTab === 'visual'
                  ? 'border-slate-900 text-slate-900 font-semibold dark:text-slate-100'
                  : 'border-transparent text-slate-500 hover:text-slate-800 font-medium dark:text-slate-400'
              }`}
            >
              <ImageIcon className="w-3.5 h-3.5" />
              <span>Visual Comparison</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('evidence')}
              className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
                activeTab === 'evidence'
                  ? 'border-slate-900 text-slate-900 font-semibold dark:text-slate-100'
                  : 'border-transparent text-slate-500 hover:text-slate-800 font-medium dark:text-slate-400'
              }`}
            >
              <ShieldCheck className="w-3.5 h-3.5" />
              <span>4-Gate Evidence & Receipt</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('history')}
              className={`py-2.5 px-1 border-b-2 text-xs inline-flex items-center gap-1.5 transition whitespace-nowrap ${
                activeTab === 'history'
                  ? 'border-slate-900 text-slate-900 font-semibold dark:text-slate-100'
                  : 'border-transparent text-slate-500 hover:text-slate-800 font-medium dark:text-slate-400'
              }`}
            >
              <History className="w-3.5 h-3.5" />
              <span>Run History</span>
              <span className="ml-1 bg-slate-100 text-slate-700 px-1.5 py-0.2 rounded text-[10px] font-semibold dark:bg-slate-800 dark:text-slate-300">
                {historyList.length}
              </span>
            </button>
          </nav>
        </div>

        {/* TAB PANE 1: WORKSPACE & PATCH */}
        {activeTab === 'workspace' && (
          <ChecksWorkspaceTab
            data={data}
            setData={setData}
            handleVerify={handleVerify}
            handlePropose={handlePropose}
            processing={processing}
            proposal={proposal}
            baselineReady={baselineReady}
            budgetStatus={budgetStatus}
          />
        )}

        {/* TAB PANE 2: VISUAL COMPARISON (Interactive Studio) */}
        {activeTab === 'visual' && (
          <div className="space-y-4">
            {hasVisual && report && receipt ? (
              <VisualComparisonViewer
                beforeUrl={`/checks/image?run=${encodeURIComponent(runId)}&image=before`}
                afterUrl={`/checks/image?run=${encodeURIComponent(runId)}&image=after`}
                outsideChangedPixels={report.visual?.outsideChangedPixels || 0}
                runId={runId}
              />
            ) : (
              <div className="bg-white rounded-xl border border-slate-200 shadow-xs p-8 text-center space-y-4 dark:bg-[#141821] dark:border-slate-800">
                <div className="w-12 h-12 rounded-full bg-accent-50 text-accent-700 flex items-center justify-center mx-auto dark:bg-accent-950/40 dark:text-accent-300">
                  <ImageIcon className="w-6 h-6" />
                </div>
                <div className="max-w-md mx-auto space-y-1.5">
                  <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                    {report?.proposal?.file?.endsWith('.php') || (report as any)?.kind === 'owned_backend'
                      ? 'Headless Backend Verification Run'
                      : 'Visual Screenshots Unavailable'}
                  </h3>
                  <p className="text-xs text-slate-500 leading-relaxed dark:text-slate-400">
                    {report?.proposal?.file?.endsWith('.php') || (report as any)?.kind === 'owned_backend'
                      ? `Run ${runId} verified PHP backend logic (${report?.proposal?.file || (report as any)?.targetFile || 'src/OrderTotal.php'}) through test assertions, negative controls, and AST safety. Headless backend tests do not generate browser DOM screenshots.`
                      : `Run ${runId} does not have browser screenshot evidence attached.`}
                  </p>
                </div>

                {latestVisualRunId && (
                  <div className="pt-2">
                    <Link
                      href={`/checks?run=${encodeURIComponent(latestVisualRunId)}&tab=visual`}
                      className="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg text-white bg-accent-700 hover:bg-accent-800 transition shadow-xs"
                    >
                      <ImageIcon className="w-4 h-4" />
                      <span>Inspect Latest Visual Checkout Check ({latestVisualRunId.slice(0, 15)}...)</span>
                      <ArrowRight className="w-3.5 h-3.5" />
                    </Link>
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* TAB PANE 3: 4-GATE EVIDENCE & RECEIPT */}
        {activeTab === 'evidence' && (
          <ChecksEvidenceTab
            report={report}
            receipt={receipt}
            receiptError={receiptError}
            runId={runId}
            copiedReceipt={copiedReceipt}
            copyReceipt={copyReceipt}
            gatesList={gatesList}
          />
        )}

        {/* TAB PANE 4: RUN HISTORY */}
        {activeTab === 'history' && (
          <ChecksHistoryTab
            filteredHistory={filteredHistory}
            historyListLength={historyList.length}
            runId={runId}
            historyQuery={historyQuery}
            setHistoryQuery={setHistoryQuery}
            paginatedHistory={paginatedHistory}
            historyTotalPages={historyTotalPages}
            safeHistoryPage={safeHistoryPage}
            historyPageSize={historyPageSize}
            setHistoryPage={setHistoryPage}
          />
        )}
      </div>
    </AppLayout>
  );
}
