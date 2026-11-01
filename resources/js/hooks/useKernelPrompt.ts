import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  GitHubConnectionData,
  IssueContext,
  Preview,
} from '../Components/workbench/types';

export type PromptViewMode = 'workbench' | 'local-card' | 'new';

interface UseKernelPromptDeps {
  githubConnection: GitHubConnectionData | null;
  context: IssueContext | null;
  preview: Preview | null;
  isDedicatedTestRepo: boolean;
  isOwnedFixture: boolean;
  setPublishingPr: (value: boolean) => void;
  setVerifying: (value: boolean) => void;
  setSending: (value: boolean) => void;
  setViewMode: (mode: PromptViewMode) => void;
}

export interface ActiveOpenPull {
  number: number;
  title: string;
  head: string;
  headSha: string;
}

/**
 * Kernel prompt: parses a maintainer's typed command and routes it to the
 * matching workbench action (dashboard confirm, publish, verify, propose) or
 * falls back to creating an automation task. Also resolves the open pull
 * request the footer acts on.
 */
export function useKernelPrompt({
  githubConnection,
  context: _context,
  preview: _preview,
  isDedicatedTestRepo: _isDedicatedTestRepo,
  isOwnedFixture: _isOwnedFixture,
  setPublishingPr,
  setVerifying,
  setSending: _setSending,
  setViewMode,
}: UseKernelPromptDeps) {
  const [commandFeedback, setCommandFeedback] = useState<string | null>(null);

  // Issue numbers in PR titles or branch names are not verified links.
  // Hide the merge shortcut until the server can provide an explicit match.
  const activeOpenPull: ActiveOpenPull | null = null;

  function executePrompt(promptText: string) {
    const q = promptText.trim().toLowerCase();
    if (!q) return;

    if (/\bmerge\b/.test(q) || /\bclose\b/.test(q)) {
      // Merging and closing need the dashboard's explicit confirmation step.
      setCommandFeedback('Opening the PR dashboard to review and confirm…');
      router.visit('/repositories');
      return;
    }

    if (/\b(create|open|publish)\b.*\b(pr|pull|draft)\b/.test(q)) {
      setCommandFeedback('Submitting draft PR request…');
      setPublishingPr(true);
      router.post('/issues/publish-pr', {}, { onFinish: () => { setPublishingPr(false); setCommandFeedback(null); } });
      return;
    }

    if (/\b(verify|check|test|gate)\b/.test(q)) {
      setCommandFeedback('Requesting checks…');
      setVerifying(true);
      router.post('/issues/verify', {}, { onFinish: () => { setVerifying(false); setCommandFeedback(null); } });
      return;
    }

    if (/\b(propose|fix|repair|solve)\b/.test(q)) {
      // The proposal card displays the exact provider request and, for the
      // private test case, asks the maintainer for separate consent. A text
      // command cannot substitute for those choices.
      setCommandFeedback('Use the proposal controls above to choose a provider and approve the request.');
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

  return { commandFeedback, activeOpenPull, executePrompt };
}
