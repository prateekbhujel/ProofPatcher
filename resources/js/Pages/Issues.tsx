import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';
import {
  LocalTaskCard,
  CardPreview,
  CardProposalPreview,
  CardProposalResult,
  NewTaskComposer,
  FocusedLocalTaskView,
} from '../Components/TaskCardSection';
import {
  GitHubConnectionData,
  IssueCompleteness,
  IssueContext,
  Preview,
  Suggestion,
  Draft,
  VerificationReport,
  WorkbenchTaskData,
  BudgetData,
  GitHubIssueItem,
} from '../Components/workbench/types';
import { DisconnectedOnboardingView } from '../Components/workbench/DisconnectedOnboardingView';
import { WorkbenchSidebar } from '../Components/workbench/WorkbenchSidebar';
import { GuidedStepBar } from '../Components/workbench/GuidedStepBar';
import { IssueHeaderCard } from '../Components/workbench/IssueHeaderCard';
import { ProposalDiffCard } from '../Components/workbench/ProposalDiffCard';
import { VerificationPipelineCard } from '../Components/workbench/VerificationPipelineCard';
import { TaskComposerFooter } from '../Components/workbench/TaskComposerFooter';

export type { IssueTriage } from '../Components/workbench/types';

interface Props {
  context: IssueContext | null;
  completeness?: IssueCompleteness | null;
  preview: Preview | null;
  suggestion: Suggestion | null;
  draft: Draft | null;
  verificationReport: VerificationReport | null;
  isOwnedFixture: boolean;
  isDedicatedTestRepo: boolean;
  publishedPr: {
    prNumber?: number;
    prUrl: string;
    branch: string;
    commitSha: string;
    draft: boolean;
    repository: string;
    publishedAt: string;
    receiptSha256: string;
  } | null;
  hasPublicationToken?: boolean;
  publisherDisconnected?: boolean;
  budget: BudgetData | null;
  error: string | null;
  tasks?: WorkbenchTaskData[];
  localTasks?: LocalTaskCard[];
  cardPreview?: CardPreview | null;
  cardProposalPreview?: CardProposalPreview | null;
  cardProposalResult?: CardProposalResult | null;
  githubConnection?: GitHubConnectionData | null;
  modelContextAllowed?: boolean;
  modelKeyAvailable?: boolean;
}

export default function Issues({
  context: suppliedContext,
  completeness,
  preview,
  suggestion,
  draft,
  verificationReport,
  isOwnedFixture,
  isDedicatedTestRepo,
  publishedPr,
  publisherDisconnected = true,
  budget,
  error,
  tasks = [],
  localTasks = [],
  cardPreview = null,
  cardProposalPreview = null,
  cardProposalResult = null,
  githubConnection = null,
  modelContextAllowed = false,
  modelKeyAvailable = false,
}: Props) {
  const context = suppliedContext;
  const capture = useForm({ issue_url: '', path: '' });
  const draftForm = useForm({ comment: draft?.comment || '' });
  const [sending, setSending] = useState(false);
  const [loadingDedicated, setLoadingDedicated] = useState(false);
  const [publishingPr, setPublishingPr] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [loadingRepo, setLoadingRepo] = useState(false);
  const [loadingIssueNum, setLoadingIssueNum] = useState<number | null>(null);
  const [sidebarTab, setSidebarTab] = useState<'issues' | 'pulls'>('issues');
  const [sidebarQuery, setSidebarQuery] = useState('');
  const [issuePathMap, setIssuePathMap] = useState<Record<number, string>>({});
  const [copiedReceipt, setCopiedReceipt] = useState(false);
  const [copiedCandidateSha, setCopiedCandidateSha] = useState(false);
  const [copiedDeviceCode, setCopiedDeviceCode] = useState(false);
  const [isSubmittingDevice, setIsSubmittingDevice] = useState(false);
  const [deviceClientIdInput, setDeviceClientIdInput] = useState(githubConnection?.clientId || '');
  const [copiedBotComment, setCopiedBotComment] = useState(false);
  const [commandFeedback, setCommandFeedback] = useState<string | null>(null);

  // Pre-fill file paths only when an issue's own title or body explicitly references a relative file path
  useEffect(() => {
    if (!githubConnection?.repoIssues) return;
    setIssuePathMap((prev) => {
      const next = { ...prev };
      let changed = false;
      for (const iss of githubConnection.repoIssues!) {
        if (next[iss.number] !== undefined) continue;
        const text = `${iss.title || ''}\n${iss.body || ''}`;
        const match = text.match(/\b([A-Za-z0-9_./-]+\.(?:php|ts|tsx|js|css|html))\b/);
        if (match && !match[1].startsWith('tests/')) {
          next[iss.number] = match[1];
          changed = true;
        } else if (/OrderTotal/i.test(text)) {
          const repo = githubConnection?.selectedRepo?.toLowerCase() || '';
          const inferred = repo.includes('proofpatch-workflow-test')
            ? 'src/OrderTotal.php'
            : repo.includes('proofpatch-review-demo')
              ? 'order_total.php'
              : '';
          if (inferred) {
            next[iss.number] = inferred;
            changed = true;
          }
        }
      }
      return changed ? next : prev;
    });
  }, [githubConnection?.selectedRepo, githubConnection?.repoIssues]);

  // Parse Markdown images / GIFs from issue description for visual repro inspection
  const detectedIssueMedia = useMemo(() => {
    const body = context?.body || '';
    const results: Array<{ alt: string; url: string; isGif: boolean }> = [];
    const mdRegex = /!\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/gi;
    let match: RegExpExecArray | null;
    while ((match = mdRegex.exec(body)) !== null) {
      const url = match[2];
      results.push({
        alt: match[1] || 'Issue attachment',
        url,
        isGif: /\.gif(\?|$)/i.test(url),
      });
    }
    return results;
  }, [context?.body]);

  // Focused pane mode: 'workbench' (active context), 'local-card' (selected local card), or 'new'
  const [selectedLocalCardId, setSelectedLocalCardId] = useState<number | null>(() => {
    if (cardPreview?.context?.cardId) return Number(cardPreview.context.cardId);
    if (!context && localTasks.length > 0) return Number(localTasks[0].id);
    return null;
  });
  const [viewMode, setViewMode] = useState<'workbench' | 'local-card' | 'new'>(() => {
    if (cardPreview?.context?.cardId) return 'local-card';
    if (context) return 'workbench';
    if (localTasks.length > 0) return 'local-card';
    return 'new';
  });

  const isConnected = Boolean(githubConnection?.isConnected && githubConnection.user);
  const previousRepo = useRef(githubConnection?.selectedRepo);

  useEffect(() => {
    if (previousRepo.current && previousRepo.current !== githubConnection?.selectedRepo) {
      setSelectedLocalCardId(null);
      setViewMode('new');
    }
    previousRepo.current = githubConnection?.selectedRepo;
  }, [githubConnection?.selectedRepo]);

  useEffect(() => {
    if (cardPreview?.context?.cardId) {
      setSelectedLocalCardId(Number(cardPreview.context.cardId));
      setViewMode('local-card');
    } else if (context) {
      setViewMode('workbench');
    }
  }, [context?.repository, context?.issueNumber, cardPreview?.digest]);

  useEffect(() => {
    if (
      githubConnection?.isConnected &&
      githubConnection.repositories &&
      githubConnection.repositories.length > 0 &&
      !githubConnection.selectedRepo &&
      !loadingRepo
    ) {
      handleSelectRepo(githubConnection.repositories[0].fullName);
    }
  }, [githubConnection?.isConnected, githubConnection?.selectedRepo, githubConnection?.repositories]);

  function handleSelectRepo(repoName: string) {
    if (!repoName) return;
    if (repoName !== githubConnection?.selectedRepo) setIssuePathMap({});
    setLoadingRepo(true);
    router.post(
      '/issues/select-repository',
      { repository: repoName },
      {
        preserveScroll: true,
        onFinish: () => setLoadingRepo(false),
      }
    );
  }

  function handleLoadIssue(issue: GitHubIssueItem) {
    if (!githubConnection?.selectedRepo) return;
    const path = issuePathMap[issue.number]?.trim() || '';
    if (!path && issue.number !== githubConnection.dedicatedIssueNumber) return;
    setLoadingIssueNum(issue.number);
    router.post(
      '/issues/load-issue',
      {
        repository: githubConnection.selectedRepo,
        issue_number: issue.number,
        path: path,
      },
      {
        onSuccess: () => setViewMode('workbench'),
        onFinish: () => setLoadingIssueNum(null),
      }
    );
  }

  function handleInitiateDeviceFlow(e?: React.FormEvent) {
    if (e) e.preventDefault();
    setIsSubmittingDevice(true);
    router.post(
      '/github/device/initiate',
      { client_id: deviceClientIdInput },
      {
        preserveScroll: true,
        onFinish: () => setIsSubmittingDevice(false),
      }
    );
  }

  function handlePollDeviceFlow(e?: React.FormEvent) {
    if (e) e.preventDefault();
    setIsSubmittingDevice(true);
    router.post(
      '/github/device/poll',
      {},
      {
        preserveScroll: true,
        onFinish: () => setIsSubmittingDevice(false),
      }
    );
  }

  function copyDeviceCode(code: string) {
    if (navigator?.clipboard?.writeText) {
      navigator.clipboard.writeText(code);
    }
    setCopiedDeviceCode(true);
    setTimeout(() => setCopiedDeviceCode(false), 2000);
  }

  function captureIssue(event: React.FormEvent) {
    event.preventDefault();
    capture.post('/issues/preview', {
      onSuccess: () => setViewMode('workbench'),
    });
  }

  function loadDedicated(event: React.MouseEvent) {
    event.preventDefault();
    setLoadingDedicated(true);
    router.post(
      '/issues/load-dedicated',
      {},
      {
        onSuccess: () => setViewMode('workbench'),
        onFinish: () => setLoadingDedicated(false),
      }
    );
  }

  function publishDraftPr(event: React.MouseEvent) {
    event.preventDefault();
    setPublishingPr(true);
    router.post(
      '/issues/publish-pr',
      {},
      {
        onFinish: () => setPublishingPr(false),
      }
    );
  }

  function requestSuggestion(event: React.FormEvent, useOfflineReference = false) {
    event.preventDefault();
    const canSubmit = !sending && Boolean(preview) && (
      useOfflineReference
        ? isDedicatedTestRepo || isOwnedFixture
        : modelContextAllowed && Boolean(budget?.canRequest && modelKeyAvailable)
    );
    if (!canSubmit) return;
    router.post(
      '/issues/propose',
      {
        digest: preview!.digest,
        approved: true,
        approval_token: preview!.approvalToken,
        offline_reference: useOfflineReference,
      },
      {
        onStart: () => setSending(true),
        onFinish: () => setSending(false),
      }
    );
  }

  function handleSaveDraft(event: React.FormEvent) {
    event.preventDefault();
    draftForm.post('/issues/draft', { preserveScroll: true });
  }

  function verifyCandidate(event: React.MouseEvent) {
    event.preventDefault();
    setVerifying(true);
    router.post(
      '/issues/verify',
      {},
      {
        onFinish: () => setVerifying(false),
      }
    );
  }

  function copyReceipt(sha: string) {
    if (navigator?.clipboard?.writeText) {
      navigator.clipboard.writeText(sha);
    }
    setCopiedReceipt(true);
    setTimeout(() => setCopiedReceipt(false), 2000);
  }

  function copyCandidateHash(sha: string) {
    if (navigator?.clipboard?.writeText) {
      navigator.clipboard.writeText(sha);
    }
    setCopiedCandidateSha(true);
    setTimeout(() => setCopiedCandidateSha(false), 2000);
  }

  const selectedLocalCard = useMemo(() => {
    const selectedRepo = githubConnection?.selectedRepo?.toLowerCase();
    const cards = localTasks.filter((card) => card.repository.toLowerCase() === selectedRepo);
    return cards.find((card) => Number(card.id) === selectedLocalCardId) || cards[0] || null;
  }, [localTasks, selectedLocalCardId, githubConnection?.selectedRepo]);

  useEffect(() => {
    if (viewMode === 'local-card' && !selectedLocalCard && githubConnection?.selectedRepo) {
      setViewMode('new');
    }
  }, [viewMode, selectedLocalCard, githubConnection?.selectedRepo]);

  const filteredIssues = useMemo(() => {
    if (!githubConnection?.repoIssues) return [];
    const q = sidebarQuery.trim().toLowerCase();
    if (!q) return githubConnection.repoIssues;
    return githubConnection.repoIssues.filter(
      (iss) =>
        iss.title.toLowerCase().includes(q) ||
        String(iss.number).includes(q) ||
        iss.user.toLowerCase().includes(q)
    );
  }, [githubConnection?.repoIssues, sidebarQuery]);

  const filteredPulls = useMemo(() => {
    if (!githubConnection?.repoPulls) return [];
    const q = sidebarQuery.trim().toLowerCase();
    if (!q) return githubConnection.repoPulls;
    return githubConnection.repoPulls.filter(
      (pull) =>
        pull.title.toLowerCase().includes(q) ||
        String(pull.number).includes(q) ||
        pull.user.toLowerCase().includes(q) ||
        pull.head.toLowerCase().includes(q)
    );
  }, [githubConnection?.repoPulls, sidebarQuery]);

  const pipelineGates = verificationReport
    ? [
        {
          label: 'Baseline Reproduction',
          shortLabel: '1. Baseline Run',
          passed: verificationReport.gates.baselineReproduced,
          detail: verificationReport.gates.baselineReproduced
            ? 'Bug reproduced (Exit 1)'
            : `Failed (Exit ${verificationReport.runs.baseline?.exitCode ?? '?'})`,
        },
        {
          label: 'Negative Control',
          shortLabel: '2. Negative Control',
          passed: verificationReport.gates.negativeControlCaught,
          detail: verificationReport.gates.negativeControlCaught
            ? 'Shortcut rejected (Exit 1)'
            : 'Negative control missed',
        },
        {
          label: 'Candidate Test',
          shortLabel: '3. Candidate Run',
          passed: verificationReport.gates.candidatePassed,
          detail: verificationReport.gates.candidatePassed
            ? 'Candidate checks passed (Exit 0)'
            : `Failed (Exit ${verificationReport.runs.candidate?.exitCode ?? '?'})`,
        },
        {
          label: 'AST Token Rules',
          shortLabel: '4. AST Safety',
          passed: verificationReport.gates.candidateStaticSafetyPassed,
          detail: verificationReport.gates.candidateStaticSafetyPassed
            ? '0 Disallowed tokens'
            : 'AST rule violation',
        },
        {
          label: 'Allowlist Hash',
          shortLabel: '5. Allowlist',
          passed: verificationReport.gates.allowlistIntegrityIntact,
          detail: verificationReport.gates.allowlistIntegrityIntact
            ? 'SHA-256 hash verified'
            : 'Integrity hash altered',
        },
      ]
    : null;

  const passedGateCount = pipelineGates ? pipelineGates.filter((g) => g.passed).length : 0;

  if (!isConnected) {
    return (
      <DisconnectedOnboardingView
        error={error}
        deviceClientIdInput={deviceClientIdInput}
        setDeviceClientIdInput={setDeviceClientIdInput}
        isSubmittingDevice={isSubmittingDevice}
        handleInitiateDeviceFlow={handleInitiateDeviceFlow}
        handlePollDeviceFlow={handlePollDeviceFlow}
        copyDeviceCode={copyDeviceCode}
        copiedDeviceCode={copiedDeviceCode}
        githubConnection={githubConnection}
      />
    );
  }

  const activeOpenPull = useMemo(() => {
    const pulls = githubConnection?.repoPulls ?? [];
    if (!context) return pulls.find((p) => p.state === 'open' && !(p as any).isDraft) || null;
    return (
      pulls.find(
        (p) =>
          p.state === 'open' &&
          !(p as any).isDraft &&
          (p.head.startsWith(`proofpatch/${context.issueNumber}-`) || p.title.includes(`#${context.issueNumber}`))
      ) ||
      pulls.find((p) => p.state === 'open' && !(p as any).isDraft) ||
      null
    );
  }, [githubConnection?.repoPulls, context?.issueNumber]);

  function handleExecutePrompt(promptText: string) {
    const q = promptText.trim().toLowerCase();
    if (!q) return;

    if (/\bmerge\b/.test(q)) {
      const prMatch = q.match(/#?(\d+)/);
      const targetPr = prMatch
        ? (githubConnection?.repoPulls ?? []).find((p) => p.number === Number(prMatch[1]))
        : activeOpenPull;
      if (targetPr && targetPr.state === 'open') {
        setCommandFeedback(`Merging PR #${targetPr.number} (${targetPr.head})…`);
        router.post(
          '/repositories/pull/merge',
          {
            repository: context?.repository || githubConnection?.selectedRepo,
            number: targetPr.number,
            head_sha: targetPr.headSha,
            method: 'squash',
          },
          { onFinish: () => setCommandFeedback(null) }
        );
        return;
      }
      setCommandFeedback('No open ready-to-merge PR found on this repository. Opening PR Dashboard…');
      router.visit('/repositories');
      return;
    }

    if (/\bclose\b/.test(q)) {
      const prMatch = q.match(/#?(\d+)/);
      const targetPr = prMatch
        ? (githubConnection?.repoPulls ?? []).find((p) => p.number === Number(prMatch[1]))
        : activeOpenPull;
      if (targetPr && targetPr.state === 'open') {
        setCommandFeedback(`Closing PR #${targetPr.number}…`);
        router.post(
          '/repositories/pull/close',
          {
            repository: context?.repository || githubConnection?.selectedRepo,
            number: targetPr.number,
            head_sha: targetPr.headSha,
          },
          { onFinish: () => setCommandFeedback(null) }
        );
        return;
      }
    }

    if (/\b(create|open|publish)\b.*\b(pr|pull|draft)\b/.test(q)) {
      setCommandFeedback('Publishing verified PR branch to GitHub…');
      setPublishingPr(true);
      router.post('/issues/publish-pr', {}, { onFinish: () => { setPublishingPr(false); setCommandFeedback(null); } });
      return;
    }

    if (/\b(verify|check|test|gate)\b/.test(q)) {
      setCommandFeedback('Running 5-Gate isolated verification checks…');
      setVerifying(true);
      router.post('/issues/verify', {}, { onFinish: () => { setVerifying(false); setCommandFeedback(null); } });
      return;
    }

    if (/\b(propose|fix|repair|solve)\b/.test(q) && preview) {
      setCommandFeedback('Generating candidate repair…');
      router.post(
        '/issues/propose',
        {
          digest: preview.digest,
          approved: true,
          approval_token: preview.approvalToken,
          offline_reference: isDedicatedTestRepo || isOwnedFixture,
        },
        {
          onStart: () => setSending(true),
          onFinish: () => { setSending(false); setCommandFeedback(null); },
        }
      );
      return;
    }

    // Otherwise create & open a new Automation Task from the user's prompt
    if (githubConnection?.selectedRepo) {
      setCommandFeedback('Creating automation task from prompt…');
      router.post(
        '/issues/tasks',
        {
          repository: githubConnection.selectedRepo,
          title: promptText.trim().slice(0, 100),
          description: promptText.trim(),
        },
        {
          onSuccess: () => {
            setCommandFeedback('Automation task created.');
            setViewMode('local-card');
          },
        }
      );
    }
  }

  return (
    <AppLayout activeNav="issues">
      <Head title="Issues & Tasks · ProofPatcher" />

      {/* Full-Screen Edge-to-Edge Desktop Workbench */}
      <div className="w-full min-h-screen bg-white dark:bg-[#141821] flex flex-col lg:flex-row">
        {/* Left Sidebar: Sticky 100vh on desktop so Settings & PRs never scroll away */}
        <WorkbenchSidebar
          githubConnection={githubConnection}
          loadingRepo={loadingRepo}
          handleSelectRepo={handleSelectRepo}
          sidebarTab={sidebarTab}
          setSidebarTab={setSidebarTab}
          sidebarQuery={sidebarQuery}
          setSidebarQuery={setSidebarQuery}
          filteredIssues={filteredIssues}
          filteredPulls={filteredPulls}
          issuePathMap={issuePathMap}
          setIssuePathMap={setIssuePathMap}
          loadingIssueNum={loadingIssueNum}
          handleLoadIssue={handleLoadIssue}
          setViewMode={setViewMode}
          localTasks={localTasks}
          onSelectTask={(id) => {
            setSelectedLocalCardId(id);
            setViewMode('local-card');
          }}
        />

        {/* Right Main Pane: Edge-to-Edge Focused Task */}
        <div className="flex-1 min-w-0 bg-white dark:bg-[#141821] flex flex-col justify-between">
          <div>
            {/* Sticky Top Context & Quick-Action Bar */}
            <div className="sticky top-0 z-20 h-12 px-6 border-b border-slate-200/80 dark:border-slate-800 bg-white/95 dark:bg-[#141821]/95 backdrop-blur-xs flex items-center justify-between gap-4">
              <div className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400 truncate">
                {viewMode === 'workbench' && context ? (
                  <>
                    <span className="font-semibold text-slate-900 dark:text-slate-100 truncate">{context.repository}</span>
                    <span className="text-slate-300 dark:text-slate-700">/</span>
                    <span className="font-mono text-slate-600 dark:text-slate-300">#{context.issueNumber}</span>
                    <span
                      className={`hidden sm:inline-block px-2 py-0.5 rounded text-[10px] font-medium border ${
                        modelContextAllowed
                          ? 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800'
                          : 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800'
                      }`}
                    >
                      {modelContextAllowed
                        ? 'Public · Muse + 5-Gate Co-Maintainer'
                        : 'Private · Local Verification Only (No external model transmission)'}
                    </span>
                  </>
                ) : viewMode === 'local-card' && selectedLocalCard ? (
                  <>
                    <span className="font-semibold text-slate-900 dark:text-slate-100 truncate">{selectedLocalCard.repository}</span>
                    <span className="text-slate-300 dark:text-slate-700">/</span>
                    <span className="font-mono text-slate-600 dark:text-slate-300">#{selectedLocalCard.id}</span>
                  </>
                ) : (
                  <span className="font-semibold text-slate-900 dark:text-slate-100">New task</span>
                )}
              </div>

              <div className="flex items-center gap-2 text-xs shrink-0">
                <Link
                  href="/repositories"
                  className="px-2.5 py-1 rounded-md border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-medium transition"
                >
                  Pull Requests ({githubConnection?.repoPulls?.length ?? 0})
                </Link>
                <Link
                  href="/settings"
                  className="px-2.5 py-1 rounded-md border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-medium transition"
                >
                  Settings
                </Link>
              </div>
            </div>

            {/* Guided Step Bar */}
            {viewMode === 'workbench' && context && (
              <GuidedStepBar
                context={context}
                suggestion={suggestion}
                verificationReport={verificationReport}
                hasPublishedPr={Boolean(publishedPr)}
              />
            )}

            {/* Pane Content */}
            <div className="mx-auto w-full max-w-5xl space-y-6 p-5 sm:p-7">
              {viewMode === 'new' && (
                <div className="space-y-6 pt-1">
                  <div className="space-y-1">
                    <h1 className="text-xl font-serif tracking-tight text-slate-900 dark:text-slate-100">
                      New task
                    </h1>
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      Create a task or open an issue from {githubConnection?.selectedRepo || 'the sidebar'}.
                    </p>
                  </div>

                  <NewTaskComposer
                    githubConnection={githubConnection}
                    onCreated={(taskId) => {
                      setSelectedLocalCardId(taskId);
                      setViewMode('local-card');
                    }}
                  />
                </div>
              )}

              {viewMode === 'local-card' && selectedLocalCard && (
                <FocusedLocalTaskView
                  task={selectedLocalCard}
                  preview={cardPreview}
                  proposalPreview={cardProposalPreview}
                  proposalResult={cardProposalResult}
                  isPrivate={githubConnection?.repositories?.find((repo) => repo.fullName.toLowerCase() === selectedLocalCard.repository.toLowerCase())?.isPrivate !== false}
                  modelKeyAvailable={modelKeyAvailable}
                  budgetCanRequest={Boolean(budget?.canRequest)}
                />
              )}

              {viewMode === 'workbench' && context && (
                <div className="space-y-6">
                  {/* Issue Header & Repro Visuals */}
                  <IssueHeaderCard
                    context={context}
                    isDedicatedTestRepo={isDedicatedTestRepo}
                    isOwnedFixture={isOwnedFixture}
                    detectedIssueMedia={detectedIssueMedia}
                    completeness={completeness}
                  />

                  {/* Proposal & Inline Diff */}
                  <ProposalDiffCard
                    context={context}
                    preview={preview}
                    suggestion={suggestion}
                    sending={sending}
                    modelContextAllowed={modelContextAllowed}
                    modelKeyAvailable={modelKeyAvailable}
                    isDedicatedTestRepo={isDedicatedTestRepo}
                    isOwnedFixture={isOwnedFixture}
                    budget={budget}
                    verificationReport={verificationReport}
                    passedGateCount={passedGateCount}
                    copiedCandidateSha={copiedCandidateSha}
                    copyCandidateHash={copyCandidateHash}
                    onRequestSuggestion={requestSuggestion}
                  />

                  {/* 5-Gate Verifier & Receipt Manifest */}
                  <VerificationPipelineCard
                    context={context}
                    suggestion={suggestion}
                    isOwnedFixture={isOwnedFixture}
                    isDedicatedTestRepo={isDedicatedTestRepo}
                    verifying={verifying}
                    verificationReport={verificationReport}
                    pipelineGates={pipelineGates}
                    copiedReceipt={copiedReceipt}
                    copyReceipt={copyReceipt}
                    copiedBotComment={copiedBotComment}
                    setCopiedBotComment={setCopiedBotComment}
                    onVerifyCandidate={verifyCandidate}
                    githubConnection={githubConnection}
                    publishedPr={publishedPr}
                    publisherDisconnected={publisherDisconnected}
                    publishingPr={publishingPr}
                    onPublishDraftPr={publishDraftPr}
                  />
                </div>
              )}
            </div>
          </div>

          {/* Bottom Composer Bar */}
          {viewMode === 'workbench' && context && (
            <TaskComposerFooter
              draftValue={draftForm.data.comment}
              onDraftChange={(val) => draftForm.setData('comment', val)}
              onSaveDraft={handleSaveDraft}
              isSaving={draftForm.processing}
              draft={draft}
              onExecutePrompt={handleExecutePrompt}
              activeOpenPull={activeOpenPull}
              commandFeedback={commandFeedback}
            />
          )}
        </div>
      </div>
    </AppLayout>
  );
}
