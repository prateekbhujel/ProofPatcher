import React from 'react';
import { MessageSquare, Play, GitMerge, GitPullRequest, Check } from 'lucide-react';
import { VoiceDictationButton } from '../VoiceDictationButton';
import { Draft } from './types';

interface TaskComposerFooterProps {
  draftValue: string;
  onDraftChange: (value: string) => void;
  onSaveDraft: (e: React.FormEvent) => void;
  isSaving: boolean;
  draft?: Draft | null;
  onExecutePrompt?: (prompt: string) => void;
  activeOpenPull?: {
    number: number;
    title: string;
    head: string;
    headSha: string;
  } | null;
  commandFeedback?: string | null;
}

export function TaskComposerFooter({
  draftValue,
  onDraftChange,
  onSaveDraft,
  isSaving,
  draft,
  onExecutePrompt,
  activeOpenPull = null,
  commandFeedback = null,
}: TaskComposerFooterProps) {
  function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter' && onExecutePrompt && draftValue.trim()) {
      e.preventDefault();
      onExecutePrompt(draftValue.trim());
    }
  }

  return (
    <div className="sticky bottom-0 z-10 border-t border-slate-200/80 dark:border-slate-800 bg-slate-50/95 dark:bg-[#11141c]/95 backdrop-blur-xs px-6 py-2.5">
      <form onSubmit={onSaveDraft} className="mx-auto w-full max-w-5xl">
        <div className="rounded-2xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 p-3 shadow-xs space-y-2">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <label htmlFor="review-draft-note" className="text-xs font-semibold text-slate-700 dark:text-slate-300">
              Maintainer Review Note &amp; Task Context · Kernel Prompt
            </label>
            <div className="flex flex-wrap items-center gap-1.5">
              {onExecutePrompt && (
                <>
                  <button
                    type="button"
                    onClick={() => onExecutePrompt('verify')}
                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-[11px] font-medium text-slate-700 dark:text-slate-300 transition"
                  >
                    <Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" />
                    <span>Verify 5 Gates</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => onExecutePrompt('create draft pr')}
                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-[11px] font-medium text-slate-700 dark:text-slate-300 transition"
                  >
                    <GitPullRequest className="w-3 h-3 text-accent-600" />
                    <span>Open PR</span>
                  </button>
                  {activeOpenPull && (
                    <button
                      type="button"
                      onClick={() => onExecutePrompt(`merge #${activeOpenPull.number}`)}
                      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-100 hover:bg-emerald-200 dark:bg-emerald-950/80 dark:hover:bg-emerald-900 text-[11px] font-semibold text-emerald-800 dark:text-emerald-300 transition"
                    >
                      <GitMerge className="w-3 h-3" />
                      <span>Merge PR #{activeOpenPull.number}</span>
                    </button>
                  )}
                </>
              )}
              <span id="review-draft-note-hint" className="text-[11px] font-mono text-slate-400">
                Cmd+Enter to run prompt · Markdown supported
              </span>
            </div>
          </div>

          <textarea
            id="review-draft-note"
            name="note"
            aria-describedby="review-draft-note-hint"
            rows={4}
            value={draftValue}
            onChange={(e) => onDraftChange(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Type a command (e.g. &quot;verify checks&quot; or &quot;create draft pr&quot;) or save maintainer review notes…"
            className="w-full h-auto min-h-[76px] max-h-[16rem] [field-sizing:content] rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 px-3 py-2 text-sm leading-relaxed text-slate-900 placeholder-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 dark:text-slate-100 resize-y transition"
          />

          {commandFeedback && (
            <div className="text-xs font-medium text-accent-700 dark:text-accent-300 bg-accent-50 dark:bg-accent-950/40 border border-accent-200 dark:border-accent-800/70 rounded-lg px-3 py-1.5">
              {commandFeedback}
            </div>
          )}

          <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400 pt-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-mono text-[11px]">
                <MessageSquare className="w-3 h-3 text-slate-500 dark:text-slate-400" aria-hidden="true" />
                Local note
              </span>
              <VoiceDictationButton
                label="Voice"
                onTranscript={(transcript) => {
                  const current = draftValue.trim();
                  onDraftChange(current ? `${current} ${transcript}` : transcript);
                }}
              />
              {draft && (
                <span className="font-mono text-[11px] text-slate-500 dark:text-slate-400">
                  Saved {draft.savedAt.replace('T', ' ').slice(0, 16)}
                </span>
              )}
            </div>
            <div className="flex items-center gap-2">
              <button
                type="submit"
                disabled={isSaving}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-semibold hover:bg-slate-50 dark:hover:bg-slate-700 disabled:opacity-40 transition"
              >
                <span>{isSaving ? 'Saving…' : 'Save note'}</span>
              </button>
              {onExecutePrompt && (
                <button
                  type="button"
                  disabled={!draftValue.trim()}
                  onClick={() => onExecutePrompt(draftValue.trim())}
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-accent-700 text-white hover:bg-accent-800 text-xs font-semibold disabled:opacity-40 transition"
                >
                  <Play className="w-3 h-3" />
                  <span>Run Prompt</span>
                </button>
              )}
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}
