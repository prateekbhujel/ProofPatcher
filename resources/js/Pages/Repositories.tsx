import React, { useState, useEffect } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';
import { copyText as writeToClipboard } from '../Components/ui/copyText';
import { GitHubConnectionSection } from '../Components/repositories/GitHubConnectionSection';
import { PrHeaderCard } from '../Components/repositories/PrHeaderCard';
import { PrTabBar } from '../Components/repositories/PrTabBar';
import { PrOverviewTab } from '../Components/repositories/PrOverviewTab';
import { PrTriageTab } from '../Components/repositories/PrTriageTab';
import { PrFilesTab } from '../Components/repositories/PrFilesTab';
import { PrReviewTab } from '../Components/repositories/PrReviewTab';
import { PrDiffsSection } from '../Components/repositories/PrDiffsSection';
import { PrReviewCommentsTab } from '../Components/repositories/PrReviewCommentsTab';
import { PrChecksTab } from '../Components/repositories/PrChecksTab';
import { PrReceiptTab } from '../Components/repositories/PrReceiptTab';
import { PrInspectForm } from '../Components/repositories/PrInspectForm';
import { PrDashboardView } from '../Components/repositories/PrDashboardView';
import { DisconnectedOnboardingView } from '../Components/workbench/DisconnectedOnboardingView';
import {
  FileText,
  AlertTriangle,
  Info,
} from 'lucide-react';
import type {
  GitHubRepo,
  GitHubUser,
  GitHubDeviceFlowData,
  GitHubPull,
  GitHubStatusMetadata,
  GitHubConnectionData,
  ChangedFile,
  CheckRun,
  PullData,
  SnapshotData,
  TriageComparison,
  TriageData,
  ReviewFinding,
  ReviewResult,
  ReviewPreviewData,
  CommentDraft,
  BudgetData,
  RepositoriesProps,
} from '../Components/repositories/types';

export default function Repositories({
  pull = null,
  canManagePull = false,
  snapshot = null,
  triage = null,
  error = null,
  patchPreview = null,
  patchPreviewError = null,
  review = null,
  reviewPreview = null,
  reviewBaseContextPath = null,
  reviewSelectedFile = null,
  reviewError = null,
  drafts = [],
  commentApprovals = {},
  commentPosted = false,
  budget = null,
  modelKeyAvailable = false,
  githubConnection = null,
}: RepositoriesProps) {
  const [activeTab, setActiveTab] = useState<'overview' | 'triage' | 'files' | 'review' | 'checks' | 'receipt' | 'new'>(
    review || reviewError || (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'review') ? 'review' : patchPreview || patchPreviewError ? 'files' : pull ? 'overview' : 'new'
  );
  const [fileQuery, setFileQuery] = useState('');
  const [checkQuery, setCheckQuery] = useState('');
  const [copiedSha, setCopiedSha] = useState(false);
  const [copiedDigest, setCopiedDigest] = useState(false);
  const [copiedReviewDigest, setCopiedReviewDigest] = useState(false);

  const [copiedFeedback, setCopiedFeedback] = useState(false);
  const [reviewApproved, setReviewApproved] = useState(false);
  const [commentToPost, setCommentToPost] = useState<string | null>(null);
  const [postingComment, setPostingComment] = useState(false);
  const [privateConsentApproved, setPrivateConsentApproved] = useState(false);
  const [reviewDecision, setReviewDecision] = useState<'comment' | 'approve' | 'request_changes'>('comment');
  const [gitHubSectionOpen, setGitHubSectionOpen] = useState(
    Boolean(githubConnection?.deviceFlow || !pull)
  );

  const isPrivate = Boolean(pull?.isPrivate || patchPreview?.isPrivate || reviewPreview?.isPrivate);

  const { data: reviewFormData, setData: setReviewFormData, post: postReview, processing: reviewProcessing } = useForm({
    digest: reviewPreview?.digest || '',
    confirm: false,
    approval_token: reviewPreview?.approvalToken || '',
    private_consent: false,
  });

  const handleDisconnectDevice = (e: React.FormEvent) => {
    e.preventDefault();
    router.post('/github/device/disconnect', {}, { preserveScroll: true });
  };

  const handleSelectRepository = (repoFullName: string) => {
    router.post('/github/repositories/select', { repository: repoFullName }, { preserveScroll: true });
  };

  const handleCaptureGitHubPull = (pullItem: GitHubPull) => {
    if (!githubConnection?.selectedRepo) return;
    if (pullItem.isPrivate && !githubConnection.canCapturePrivateTestRepo) return;
    router.post('/github/pulls/capture', {
      repository: githubConnection.selectedRepo,
      number: pullItem.number,
    });
  };

  useEffect(() => {
    if (reviewPreview?.approvalToken) {
      setReviewFormData('approval_token', reviewPreview.approvalToken);
      setReviewFormData('digest', reviewPreview.digest);
      setReviewFormData('confirm', false);
      setReviewApproved(false);
    }
  }, [reviewPreview?.approvalToken, reviewPreview?.digest]);

  const { post: postClearReview, processing: clearReviewProcessing } = useForm({});

  const { transform: transformDraft, post: postDraft, processing: draftProcessing } = useForm({
    path: '',
    line: null as number | null,
    side: 'RIGHT',
    comment: '',
    headSha: pull?.headSha || '',
  });

  const { post: postClearDrafts, processing: clearDraftsProcessing } = useForm({});

  const handleReviewSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!reviewPreview || !reviewApproved) return;
    if (isPrivate && !privateConsentApproved) return;
    postReview('/repositories/review');
  };

  const handleClearReview = (e: React.FormEvent) => {
    e.preventDefault();
    postClearReview('/repositories/review/clear');
  };

  const handleSaveDraft = (path: string, line: number | null, side: string, comment: string, onSaved?: () => void) => {
    if (!comment.trim() || !pull || draftProcessing) return;

    transformDraft(() => ({
      path,
      line,
      side,
      comment: comment.trim(),
      headSha: pull.headSha,
    }));
    postDraft('/repositories/review/draft', {
      preserveScroll: true,
      preserveState: true,
      onSuccess: (page) => {
        // A redirect can carry a save error with HTTP 200. Close only after
        // the returned drafts confirm this exact comment was persisted.
        const saved = page.props.drafts as CommentDraft[] | undefined;
        if (Array.isArray(saved) && saved.some((draft) =>
          draft.path === path && draft.line === line && draft.side === side &&
          draft.comment === comment.trim() && draft.headSha === pull.headSha
        )) onSaved?.();
      },
    });
  };

  const handleDeleteDraft = (id: string) => {
    router.post('/repositories/review/draft/delete', { id }, { preserveScroll: true });
  };

  const handleClearAllDrafts = (e: React.FormEvent) => {
    e.preventDefault();
    postClearDrafts('/repositories/review/draft/clear', { preserveScroll: true });
  };

  const { data, setData, post, processing } = useForm({
    url: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/repositories');
  };

  const copyText = (text: string, type: 'sha' | 'digest' | 'reviewDigest') => {
    void writeToClipboard(text).then((ok) => {
      if (!ok) return;
      if (type === 'sha') {
        setCopiedSha(true);
        setTimeout(() => setCopiedSha(false), 1500);
      } else if (type === 'digest') {
        setCopiedDigest(true);
        setTimeout(() => setCopiedDigest(false), 1500);
      } else {
        setCopiedReviewDigest(true);
        setTimeout(() => setCopiedReviewDigest(false), 1500);
      }
    });
  };

  const files = pull?.files || [];
  const checks = pull?.checks || [];

  const filteredFiles = files.filter((f) =>
    f.name.toLowerCase().includes(fileQuery.toLowerCase().trim())
  );

  const filteredChecks = checks.filter(
    (c) =>
      c.name.toLowerCase().includes(checkQuery.toLowerCase().trim()) ||
      (c.conclusion || c.status).toLowerCase().includes(checkQuery.toLowerCase().trim())
  );

  const totalAdditions = files.reduce((acc, f) => acc + (f.additions || 0), 0);
  const totalDeletions = files.reduce((acc, f) => acc + (f.deletions || 0), 0);
  const passedChecks = checks.filter(
    (c) => (c.conclusion || c.status).toLowerCase() === 'success'
  ).length;

  if (!githubConnection?.isConnected) {
    return (
      <DisconnectedOnboardingView
          error={error}
          githubConnection={githubConnection as any}
          redirectTo="/repositories"
      />
    );
  }

  return (
    <AppLayout activeNav="repositories">
      <Head title={pull ? `${pull.repository}#${pull.number}` : 'Inspect Pull Request'} />

      <div className="space-y-6">
        {/* Error notification */}
        {error && error !== reviewError && (
          <div role="alert" className="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
            <span>{error}</span>
          </div>
        )}
        {reviewError && activeTab !== 'review' && (
          <div role="alert" className="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
            <span>{reviewError}</span>
          </div>
        )}

        <GitHubConnectionSection
          githubConnection={githubConnection}
          gitHubSectionOpen={gitHubSectionOpen}
          setGitHubSectionOpen={setGitHubSectionOpen}
          handleDisconnectDevice={handleDisconnectDevice}
          handleSelectRepository={handleSelectRepository}
          handleCaptureGitHubPull={handleCaptureGitHubPull}
        />

        {pull ? (
          <>
            {/* PR Header Card & Co-Maintainer Context */}
            <PrHeaderCard
              pull={pull}
              canManagePull={canManagePull}
              triage={triage}
              onViewTriage={() => setActiveTab('triage')}
            />

            {/* Sub-Navigation Tabs */}
            <PrTabBar
              activeTab={activeTab}
              setActiveTab={setActiveTab}
              triageComparisonsCount={triage?.comparisons?.length || 0}
              filesCount={files.length}
              reviewRiskLevel={review?.riskLevel}
              draftsCount={drafts.length}
              passedChecks={passedChecks}
              checksCount={checks.length}
            />

            {/* TAB PANE 1: OVERVIEW */}
            {activeTab === 'overview' && (
              <PrOverviewTab
                filesCount={files.length}
                totalAdditions={totalAdditions}
                totalDeletions={totalDeletions}
                checksCount={checks.length}
                passedChecks={passedChecks}
              />
            )}

            {/* TAB PANE 2: TRIAGE & OVERLAP */}
            {activeTab === 'triage' && (
              <PrTriageTab
                triage={triage}
                copiedFeedback={copiedFeedback}
                setCopiedFeedback={setCopiedFeedback}
                onVerify5Gates={() => setActiveTab('checks')}
              />
            )}

            {/* TAB PANE 3: CHANGED FILES */}
            {activeTab === 'files' && (
              <PrFilesTab
                filteredFiles={filteredFiles}
                fileQuery={fileQuery}
                setFileQuery={setFileQuery}
                patchPreview={patchPreview}
                snapshot={snapshot}
                patchPreviewError={patchPreviewError}
                headSha={pull.headSha}
                findings={review?.fileFindings || []}
                drafts={drafts}
                draftProcessing={draftProcessing}
                onSaveDraft={handleSaveDraft}
                onDeleteDraft={handleDeleteDraft}
              />
            )}

            {/* TAB PANE: STRUCTURED REVIEW & DIFFS */}
            {activeTab === 'review' && (
              <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-6 space-y-6 dark:bg-[#141821] dark:border-slate-800/80">
                <div>
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                      <h2 className="text-base font-semibold text-slate-900 flex items-center gap-2 dark:text-slate-100">
                        <FileText className="w-4 h-4 text-slate-800 dark:text-slate-200" />
                        <span>Review & Inline Findings</span>
                      </h2>
                      <p className="text-xs text-slate-500 mt-0.5 dark:text-slate-400">
                        Diff-first code review. Inspect captured diffs, review advisory findings, and draft local line comments.
                      </p>
                    </div>

                    {review && (
                      <form onSubmit={handleClearReview}>
                        <button
                          type="submit"
                          disabled={clearReviewProcessing}
                          className="px-3 py-1.5 text-xs font-medium rounded-md text-slate-600 bg-white border border-slate-300 hover:bg-slate-50 shadow-2xs transition disabled:opacity-50 dark:bg-[#141821] dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
                        >
                          Start another review
                        </button>
                      </form>
                    )}
                  </div>
                </div>

                {/* Honest Disclosure Alert */}
                <div className="p-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-700 text-xs flex items-start gap-2.5 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300">
                  <Info className="w-4 h-4 text-slate-500 shrink-0 mt-0.5 dark:text-slate-400" />
                  <div className="space-y-0.5">
                    <p className="font-semibold text-slate-900 dark:text-slate-100">Advisory Review Only</p>
                    <p className="text-slate-600 text-[11px] leading-relaxed dark:text-slate-400">
                      Review suggestions are advisory. Individual comments can be posted manually to the configured test repository. External repository tests are not run here.
                    </p>
                  </div>
                </div>

                {reviewError && (
                  <div role="alert" className="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300">
                    <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 dark:text-rose-400" />
                    <span>{reviewError}</span>
                  </div>
                )}

                {/* Top Review Verdict Summary & Pre-review Consent */}
                <PrReviewTab
                  review={review}
                  reviewPreview={reviewPreview}
                  changedFiles={patchPreview?.files.filter((file) => file.status === 'available').map((file) => file.name) || []}
                  baseContextPath={reviewBaseContextPath}
                  selectedFile={reviewSelectedFile}
                  isPrivate={Boolean(isPrivate)}
                  reviewApproved={reviewApproved}
                  setReviewApproved={setReviewApproved}
                  reviewProcessing={reviewProcessing}
                  budget={budget}
                  modelKeyAvailable={modelKeyAvailable}
                  copiedReviewDigest={copiedReviewDigest}
                  copyText={copyText}
                  handleReviewSubmit={handleReviewSubmit}
                  setReviewFormData={setReviewFormData}
                  reviewError={reviewError}
                />

                {/* Diff-First Layout: Files & Inline Findings */}
                <PrDiffsSection
                  files={files}
                  patchPreview={patchPreview}
                  pullHeadSha={pull.headSha}
                  findings={review?.fileFindings || []}
                  drafts={drafts}
                  draftProcessing={draftProcessing}
                  snapshotId={snapshot?.id}
                  onSaveDraft={handleSaveDraft}
                  onDeleteDraft={handleDeleteDraft}
                />

                {/* Local Saved Drafts & Review Decision Bar */}
                <div className="p-5 rounded-xl border border-slate-200 bg-white shadow-2xs space-y-4 dark:bg-[#141821] dark:border-slate-800">
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 dark:border-slate-800">
                    <div>
                      <h3 className="text-xs font-semibold text-slate-900 dark:text-slate-100">Maintainer Review Decision (Local Draft)</h3>
                      <p className="text-[11px] text-slate-500 mt-0.5 dark:text-slate-400">
                        Record a maintainer conclusion and draft comments for this pull request.
                      </p>
                    </div>
                    {/* Draft Comments */}
                    <PrReviewCommentsTab
                      drafts={drafts}
                      reviewDecision={reviewDecision}
                      setReviewDecision={setReviewDecision}
                      commentApprovals={commentApprovals}
                      commentToPost={commentToPost}
                      setCommentToPost={setCommentToPost}
                      postingComment={postingComment}
                      setPostingComment={setPostingComment}
                      commentPosted={commentPosted}
                      clearDraftsProcessing={clearDraftsProcessing}
                      handleClearAllDrafts={handleClearAllDrafts}
                      githubConnection={githubConnection}
                    />
                  </div>
                </div>
              </div>
            )}

            {/* TAB PANE 4: GITHUB CHECKS */}
            {activeTab === 'checks' && (
              <PrChecksTab
                checks={checks}
                filteredChecks={filteredChecks}
                checkQuery={checkQuery}
                setCheckQuery={setCheckQuery}
                headSha={pull.headSha}
              />
            )}

            {/* TAB PANE 5: RECEIPT */}
            {activeTab === 'receipt' && (
              <PrReceiptTab
                snapshot={snapshot}
                copiedDigest={copiedDigest}
                copyText={copyText}
              />
            )}

            {/* TAB PANE 6: INSPECT ANOTHER */}
            {activeTab === 'new' && (
              <PrInspectForm
                url={data.url}
                setUrl={(val) => setData('url', val)}
                onSubmit={handleSubmit}
                processing={processing}
              />
            )}
          </>
        ) : (
          /* Automated GitHub Pull Requests Stream Dashboard */
          <PrDashboardView
            githubConnection={githubConnection}
            handleSelectRepository={handleSelectRepository}
            handleCaptureGitHubPull={handleCaptureGitHubPull}
            inspectUrl={data.url}
            setInspectUrl={(val) => setData('url', val)}
            handleInspectSubmit={handleSubmit}
            inspectProcessing={processing}
          />
        )}
      </div>
    </AppLayout>
  );
}
