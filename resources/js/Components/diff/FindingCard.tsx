import React from 'react';
import { Sparkles, Edit3 } from 'lucide-react';
import { ReviewFinding, CommentDraft } from './types';

interface FindingCardProps {
  finding: ReviewFinding;
  headSha: string;
  matchingDraft?: CommentDraft | null;
  isEditorOpen: boolean;
  lineKey: string;
  openEditorForLine: (lineKey: string, defaultText?: string) => void;
  closeEditor: () => void;
}

/** House style (docs/REVIEW_COMMENT_STYLE.md): label chip, subject, discussion, fix. */
const LABELS: Record<string, string> = { concern: 'issue', note: 'nitpick', approval: 'praise' };

function splitComment(comment: string): { subject: string; discussion: string } {
  const text = comment.trim();
  if (!text) return { subject: '', discussion: '' };
  const boundary = text.search(/(?<=[.?!])\s+/);
  let subject = (boundary < 0 ? text : text.slice(0, boundary)).trim();
  let discussion = boundary < 0 ? '' : text.slice(boundary).trim();
  if (subject.length > 160) {
    subject = `${subject.slice(0, 157).trimEnd()}…`;
    discussion = text;
  }
  return { subject, discussion };
}

/** CodeRabbit-style finding card: verdict badge, explanation, suggested fix, anchor proof. */
export function FindingCard({
  finding,
  headSha,
  matchingDraft,
  isEditorOpen,
  lineKey,
  openEditorForLine,
  closeEditor,
}: FindingCardProps) {
  const label = LABELS[finding.status] ?? 'note';
  const { subject, discussion } = splitComment(finding.comment);
  return (
    <tr>
      <td colSpan={5} className="p-3 bg-[var(--bg-sunken)] border-y border-[var(--border)]">
        <div className="rounded-lg bg-[var(--bg-raised)] border border-[var(--border)] shadow-2xs p-3.5 space-y-3 font-sans">
          {/* Bot Header */}
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 dark:border-slate-800">
            <div className="flex items-center gap-2">
              <div className="w-6 h-6 rounded-full bg-accent-700 text-white flex items-center justify-center text-xs font-bold">
                <Sparkles className="w-3.5 h-3.5" />
              </div>
              <span className="text-xs font-semibold text-[#1f2328] dark:text-slate-100">Muse suggestion</span>
              <span className="text-slate-300">·</span>
              <span
                className={`px-2 py-0.2 rounded text-[10px] font-bold uppercase tracking-wider ${
                  label === 'praise'
                    ? 'bg-[#dafbe1] text-[#1a7f37]'
                    : label === 'issue'
                    ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'
                    : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200'
                }`}
              >
                {label}
              </span>
            </div>

            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() =>
                  isEditorOpen
                    ? closeEditor()
                    : openEditorForLine(
                        lineKey,
                        matchingDraft?.comment || finding.comment
                      )
                }
                className="inline-flex items-center gap-1 text-xs font-semibold text-accent-700 hover:text-accent-900 underline dark:text-accent-300"
              >
                <Edit3 className="w-3.5 h-3.5" />
                <span>
                  {isEditorOpen
                    ? 'Close Editor'
                    : matchingDraft
                    ? 'Edit Local Draft'
                    : 'Adopt into Review Draft'}
                </span>
              </button>
            </div>
          </div>

          {/* Subject + discussion */}
          <div className="text-xs text-[#1f2328] dark:text-slate-200 leading-relaxed whitespace-pre-wrap">
            <p className="font-semibold">{subject}</p>
            {discussion !== '' && discussion !== subject && <p className="mt-1 font-normal">{discussion}</p>}
          </div>

          {finding.suggestion != null && finding.suggestion !== '' && (
            <div>
              <div className="text-[11px] font-semibold text-slate-700 dark:text-slate-300 mb-1">Suggested fix</div>
              <pre className="p-2.5 rounded bg-slate-50 border border-slate-200 text-slate-800 font-mono text-[11px] leading-relaxed whitespace-pre-wrap dark:bg-slate-900 dark:border-slate-800 dark:text-slate-200">
                {finding.suggestion}
              </pre>
            </div>
          )}

          {/* Collapsible AI Prompt & Context */}
          <details className="group/details text-[11px] text-slate-500 border-t border-slate-100 pt-2 dark:border-slate-800 dark:text-slate-400">
            <summary className="cursor-pointer hover:text-slate-800 font-medium select-none flex items-center gap-1 text-slate-600 dark:text-slate-400">
              <span>▶ Diff context & anchor verification</span>
              <span className="text-2xs text-slate-400 font-mono">
                ({finding.path}:{finding.lineHint} · SHA:{' '}
                {headSha.slice(0, 8)})
              </span>
            </summary>
            <p className="mt-1.5 p-2 rounded bg-slate-50 border border-slate-200 text-slate-700 font-mono text-[11px] leading-relaxed dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300">
              This suggestion matches line {finding.lineHint} in the captured patch.
              Review its content before posting.
            </p>
          </details>
        </div>
      </td>
    </tr>
  );
}
