import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Sparkles,
  Lock,
  Copy,
  Check,
  CheckCircle2,
  AlertTriangle,
} from 'lucide-react';
import { BudgetData, ReviewPreviewData, ReviewResult } from './types';
import { reviewUsageLine } from '../ui/budgetCopy';

interface PrReviewTabProps {
  review?: ReviewResult | null;
  reviewPreview?: ReviewPreviewData | null;
  changedFiles: string[];
  baseContextPath: string | null;
  selectedFile?: string | null;
  isPrivate: boolean;
  reviewApproved: boolean;
  setReviewApproved: (approved: boolean) => void;
  reviewProcessing: boolean;
  budget?: BudgetData | null;
  modelKeyAvailable?: boolean;
  copiedReviewDigest: boolean;
  copyText: (text: string, type: any) => void;
  handleReviewSubmit: (e: React.FormEvent) => void;
  setReviewFormData: (key: string, val: unknown) => void;
  reviewError?: string | null;
}

export function PrReviewTab({
  review,
  reviewPreview,
  changedFiles,
  baseContextPath,
  selectedFile = null,
  isPrivate,
  reviewApproved,
  setReviewApproved,
  reviewProcessing,
  budget,
  modelKeyAvailable,
  copiedReviewDigest,
  copyText,
  handleReviewSubmit,
  setReviewFormData,
  reviewError,
}: PrReviewTabProps) {
  const [selectedContext, setSelectedContext] = useState(baseContextPath || '');
  const [fileScope, setFileScope] = useState(selectedFile || '');
  useEffect(() => setSelectedContext(baseContextPath || ''), [baseContextPath, selectedFile]);
  useEffect(() => setFileScope(selectedFile || ''), [selectedFile]);
  return (
    <div className="space-y-4 rounded-xl border border-slate-200/80 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-[#141821]">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
        <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">Muse review</h2>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {review ? 'Review these suggestions before acting on them.' : isPrivate ? 'Private pull requests stay local and are not sent to Muse.' : 'Ask Muse to review the selected public diff.'}
        </p>
        </div>
        {!review && isPrivate && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">Not available yet</span>}
      </div>

      {reviewError && (
        <div role="alert" className="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
          <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 dark:text-rose-400" />
          <span>{reviewError}</span>
        </div>
      )}

      {review && (
        <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Sparkles className="w-4 h-4 text-accent-700 dark:text-accent-300" />
              <span className="text-sm font-semibold text-slate-900 dark:text-slate-100">Muse suggestions</span>
              <span className="text-[11px] text-slate-500 dark:text-slate-400">
                {review.source === 'automatic' ? '· Automatic review' : '· Manual review'}
              </span>
            </div>
            {!review.coverage && <span
              className={`px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider ${
                review.riskLevel === 'low'
                  ? 'bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-300'
                  : review.riskLevel === 'medium'
                  ? 'bg-amber-50 text-amber-800 border border-amber-200 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-300'
                  : 'bg-rose-50 text-rose-800 border border-rose-200 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300'
              }`}
            >
              Estimated {review.riskLevel} risk
            </span>}
          </div>
          {review.coverage && <p className="text-xs font-medium text-amber-800 dark:text-amber-300">Selected file only: {review.coverage.path}. {review.coverage.totalFiles - review.coverage.reviewedFiles} other changed files were not reviewed. No whole-PR verdict.</p>}

          <div className="border-t border-slate-100 pt-2.5 dark:border-slate-800">
            <h3 className="mb-0.5 text-xs font-semibold text-slate-900 dark:text-slate-100">Summary</h3>
            <p className="text-sm leading-relaxed text-slate-700 dark:text-slate-300">{review.summary}</p>
          </div>

          <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-100 text-[11px] text-slate-500 font-mono dark:border-slate-800 dark:text-slate-400">
            <span>Tokens: {review.usage.prompt_tokens} in / {review.usage.completion_tokens} out</span>
            <span>Reviewed: {new Date(review.reviewedAt).toLocaleString()}</span>
          </div>
          <p className="text-[11px] text-slate-500 dark:text-slate-400">
            Advisory only · {review.publishedStatus === 'one_inline_comment' ? 'One suggestion posted to GitHub.' : review.publishedStatus === 'review_summary' ? 'Review summary posted to GitHub.' : review.publishedStatus === 'publication_unknown' ? 'Comment outcome unknown. Check GitHub.' : 'Stored in ProofPatcher · Not posted to GitHub.'} External repository tests were not run here.
          </p>
        </div>
      )}

      {!review && !isPrivate && changedFiles.length > 0 && (
        <div className="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
          <label htmlFor="review-file-scope" className="block text-xs font-medium text-slate-800 dark:text-slate-200">Review scope</label>
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Choose one complete file if the full diff is unavailable. Other files will not be reviewed.</p>
          <div className="mt-2 flex flex-wrap gap-2">
            <select id="review-file-scope" value={fileScope} onChange={(e) => setFileScope(e.target.value)} className="min-w-0 flex-1 rounded border border-slate-300 bg-white p-2 text-xs dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
              <option value="">Full pull request</option>
              {changedFiles.map((path) => <option key={path} value={path}>{path}</option>)}
            </select>
            <button type="button" onClick={() => router.post('/repositories/review/file', { path: fileScope })} className="rounded border border-slate-300 px-3 py-2 text-xs dark:border-slate-700 dark:text-slate-200">Set scope</button>
          </div>
        </div>
      )}

      {/* Pre-review: Technical Request Disclosure & Maintainer Consent */}
      {!review && reviewPreview && (
        <div className="space-y-4">
          {!isPrivate && changedFiles.length > 0 && (
            <div className="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
              <label htmlFor="base-review-context" className="block text-xs font-medium text-slate-800 dark:text-slate-200">Optional base-file context</label>
              <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Add a few pre-change lines from one public changed file. This is not the full file.</p>
              <div className="mt-2 flex flex-wrap gap-2">
                <select id="base-review-context" value={selectedContext} onChange={(e) => setSelectedContext(e.target.value)} className="min-w-0 flex-1 rounded border border-slate-300 bg-white p-2 text-xs dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                  <option value="">Diff only</option>
                  {changedFiles.filter((path) => !selectedFile || selectedFile === path).map((path) => <option key={path} value={path}>{path}</option>)}
                </select>
                <button type="button" onClick={() => router.post('/repositories/review/base-context', { path: selectedContext })} className="rounded border border-slate-300 px-3 py-2 text-xs dark:border-slate-700 dark:text-slate-200">Update preview</button>
              </div>
              {baseContextPath && <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Included: {baseContextPath}</p>}
            </div>
          )}
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600 bg-slate-50 p-3 rounded-lg border border-slate-200 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-400">
            <span>{reviewPreview.filesCount} diff {reviewPreview.filesCount === 1 ? 'file' : 'files'} included in review scope</span>
            <span className="font-mono text-[11px]">~${(reviewPreview.estimatedMicros / 1000000).toFixed(4)} estimated reservation</span>
          </div>
          {reviewPreview.coverage && <p className="text-xs font-medium text-amber-800 dark:text-amber-300">Only {reviewPreview.coverage.path} will be reviewed. {reviewPreview.coverage.totalFiles - reviewPreview.coverage.reviewedFiles} other changed files will not be reviewed.</p>}

          {/* Accessible Full Request Inspection Disclosure */}
          <details className="border border-slate-200 rounded-lg p-3 bg-white space-y-3 dark:bg-[#141821] dark:border-slate-800">
            <summary className="cursor-pointer text-xs font-semibold text-slate-800 flex items-center justify-between dark:text-slate-200">
              <span>Review what will be sent</span>
            </summary>
            <div className="space-y-3 pt-3 text-xs border-t border-slate-100 dark:border-slate-800">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px]">
                <div><span className="text-slate-500 dark:text-slate-400">Destination:</span> <code className="font-mono text-slate-800 dark:text-slate-200">{reviewPreview.destination}</code></div>
                <div><span className="text-slate-500 dark:text-slate-400">Model:</span> <code className="font-mono text-slate-800 dark:text-slate-200">{(reviewPreview.body as any)?.model || 'meta-models/muse-spark-open-1026'}</code></div>
                <div className="col-span-2 flex items-center justify-between gap-2">
                  <span className="text-slate-500 dark:text-slate-400">Payload SHA-256 Digest:</span>
                  <button
                    type="button"
                    onClick={() => copyText(reviewPreview.digest, 'reviewDigest')}
                    className="inline-flex items-center gap-1 text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                  >
                    {copiedReviewDigest ? <Check className="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                    <span className="text-[10px]">{copiedReviewDigest ? 'Copied' : 'Copy'}</span>
                  </button>
                </div>
                <p className="col-span-2 font-mono text-[11px] text-slate-700 break-all bg-slate-50 p-2 rounded border border-slate-200 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300">
                  {reviewPreview.digest}
                </p>
              </div>

              {reviewPreview.body && (
                <div>
                  <span className="text-slate-500 text-[11px] font-medium dark:text-slate-400">Exact Request Body (Sent to engine):</span>
                  <pre className="mt-1.5 p-3 rounded bg-slate-900 text-slate-100 text-[11px] font-mono overflow-x-auto max-h-80 whitespace-pre-wrap">
                    {JSON.stringify(reviewPreview.body, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          </details>

          {/* Explicit Consent Form & Honest Budget Status */}
          <form onSubmit={handleReviewSubmit} className="p-4 rounded-lg border border-slate-200 bg-white space-y-3 dark:bg-[#141821] dark:border-slate-800">
            {isPrivate && (
              <div className="p-3.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-950 text-xs space-y-1.5 dark:bg-amber-950/40 dark:border-amber-800">
                <div className="flex items-center gap-2 font-semibold text-amber-900 dark:text-amber-200">
                  <Lock className="w-4 h-4 text-amber-700 shrink-0 dark:text-amber-400" />
                  <span>Private Repository Review Unsupported</span>
                </div>
                <p className="text-[11px] leading-relaxed text-amber-900 dark:text-amber-200">
                  Private pull requests stay in this session and are not sent to a model.
                </p>
              </div>
            )}

            <label className="flex items-start gap-2.5 cursor-pointer text-xs text-slate-700 dark:text-slate-300">
              <input
                type="checkbox"
                checked={reviewApproved}
                disabled={isPrivate}
                onChange={(e) => {
                  setReviewApproved(e.target.checked);
                  setReviewFormData('confirm', e.target.checked);
                  setReviewFormData('digest', reviewPreview.digest);
                  setReviewFormData('approval_token', reviewPreview.approvalToken || '');
                }}
                className="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-900 dark:border-slate-700 dark:text-slate-100 disabled:opacity-40"
              />
              <span className="leading-relaxed">
                {isPrivate
                  ? 'Private pull requests stay local and are not sent to a model.'
                  : baseContextPath
                    ? 'I approve sending this public diff and selected base-file context to Muse for review.'
                    : 'I approve sending this public diff to Muse for review.'}
              </span>
            </label>

            <div className="flex flex-wrap items-center justify-between gap-3 pt-1 border-t border-slate-100 dark:border-slate-800">
              <div className="text-xs">
                {budget ? (
                  <span className="text-slate-600 dark:text-slate-400">
                    Usage: <strong className="font-mono text-slate-900 dark:text-slate-100">{reviewUsageLine(budget.attempts, budget.maxAttempts)}</strong>
                  </span>
                ) : (
                  <span className="text-slate-400">Budget status unknown</span>
                )}
              </div>

              <button
                type="submit"
                disabled={reviewProcessing || !reviewApproved || isPrivate || !budget?.canRequest || !modelKeyAvailable}
                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-accent-700 text-white text-xs font-semibold hover:bg-accent-800 disabled:opacity-40 transition shadow-xs"
              >
                <Sparkles className="w-3.5 h-3.5" />
                <span>{reviewProcessing ? 'Reviewing…' : 'Run Muse review'}</span>
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
