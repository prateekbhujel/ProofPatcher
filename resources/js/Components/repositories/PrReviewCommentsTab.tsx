import React from 'react';
import { router } from '@inertiajs/react';
import { GitHubConnectionData, CommentDraft } from './types';

interface PrReviewCommentsTabProps {
  drafts: CommentDraft[];
  reviewDecision: 'comment' | 'approve' | 'request_changes';
  setReviewDecision: (decision: 'comment' | 'approve' | 'request_changes') => void;
  commentApprovals: Record<string | number, string>;
  commentToPost: string | null;
  setCommentToPost: (val: string | null) => void;
  postingComment: boolean;
  setPostingComment: (val: boolean) => void;
  commentPosted?: boolean;
  clearDraftsProcessing: boolean;
  handleClearAllDrafts: (e: React.FormEvent) => void;
  githubConnection: GitHubConnectionData | null;
}

export function PrReviewCommentsTab({
  drafts,
  commentApprovals,
  commentToPost,
  setCommentToPost,
  postingComment,
  setPostingComment,
  commentPosted,
  clearDraftsProcessing,
  handleClearAllDrafts,
  githubConnection,
}: PrReviewCommentsTabProps) {
  return (
    <div className="space-y-4 rounded-xl border border-slate-200/80 bg-white p-5 dark:border-slate-800 dark:bg-[#141821]">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">Draft comments</h2>
          <p className="text-sm text-slate-500 dark:text-slate-400">
            Review your comments before posting them.
          </p>
        </div>
        {drafts.length > 0 && (
          <form onSubmit={handleClearAllDrafts}>
            <button
              type="submit"
              disabled={clearDraftsProcessing}
              className="text-[11px] font-medium text-rose-600 hover:text-rose-800 underline disabled:opacity-50"
            >
              Clear All Drafts ({drafts.length})
            </button>
          </form>
        )}
      </div>

      {commentPosted && <p role="status" className="text-xs text-emerald-700">Comment posted on GitHub.</p>}

      {drafts.length === 0 && <p className="text-sm text-slate-500 dark:text-slate-400">No draft comments yet. Add one from a changed line.</p>}
      {drafts.map((draft) => (
          <div key={draft.id} className="space-y-3 rounded-lg border border-slate-200 p-4 text-sm dark:border-slate-700">
            <p className="font-medium text-slate-800 dark:text-slate-200">
              {draft.path}:{draft.line} ({draft.side})
            </p>
            <p className="whitespace-pre-wrap break-words text-slate-700 dark:text-slate-300">{draft.comment}</p>
            {commentApprovals[draft.id] ? <>
            <label className="flex items-center gap-2 text-slate-700 dark:text-slate-300">
              <input
                type="checkbox"
                checked={commentToPost === commentApprovals[draft.id]}
                onChange={(event) =>
                  setCommentToPost(event.target.checked ? commentApprovals[draft.id] : null)
                }
              />
              <span>Post this comment to the test repository{githubConnection?.user?.login ? ` as @${githubConnection.user.login}` : ''}</span>
            </label>
            <button
              type="button"
              disabled={postingComment || commentToPost !== commentApprovals[draft.id]}
              onClick={() => {
                setPostingComment(true);
                router.post(
                  '/repositories/review/comment',
                  {
                    id: draft.id,
                    digest: commentApprovals[draft.id],
                    approved: true,
                  },
                  {
                    onFinish: () => {
                      setPostingComment(false);
                      setCommentToPost(null);
                    },
                  }
                );
              }}
              className="px-3 py-1.5 rounded-md bg-accent-700 text-white font-semibold disabled:opacity-40"
            >
              {postingComment && commentToPost === commentApprovals[draft.id]
                ? 'Posting…'
                : 'Post comment'}
            </button>
            </> : <p className="text-xs text-slate-500 dark:text-slate-400">This draft is saved locally. Posting it is not available for this pull request.</p>}
          </div>
        ))}

      <div className="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-400">
        <span>Submit an approval or change request</span>
        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">Coming soon</span>
      </div>
    </div>
  );
}
