import React from 'react';
import { Plus, MessageSquare } from 'lucide-react';
import { DiffLine, ReviewFinding, CommentDraft } from './types';
import { InlineCommentEditor } from './InlineCommentEditor';
import { FindingCard } from './FindingCard';
import { InlineDraft } from './InlineDraft';

interface UnifiedDiffTableProps {
  fileName: string;
  headSha: string;
  parsedLines: DiffLine[];
  anchoredFindings: ReviewFinding[];
  fileDrafts: CommentDraft[];
  activeEditorLineKey: string | null;
  openEditorForLine: (lineKey: string, defaultText?: string) => void;
  closeEditor: () => void;
  onSaveDraft: (fileName: string, lineNum: number, side: string, text: string) => void;
  onDeleteDraft?: (draftId: string) => void;
  isSavingDraft?: boolean;
}

export function UnifiedDiffTable({
  fileName,
  headSha,
  parsedLines,
  anchoredFindings,
  fileDrafts,
  activeEditorLineKey,
  openEditorForLine,
  closeEditor,
  onSaveDraft,
  onDeleteDraft,
  isSavingDraft = false,
}: UnifiedDiffTableProps) {
  return (
    <div className="overflow-x-auto text-[12px] font-mono leading-5">
      <table className="w-full border-collapse table-fixed">
        <colgroup>
          <col className="w-12" />
          <col className="w-12" />
          <col className="w-7" />
          <col className="w-6" />
          <col className="w-[calc(100%-8.5rem)]" />
        </colgroup>
        <tbody>
          {parsedLines.map((line, lIdx) => {
            const lineNum = line.side === 'RIGHT' ? line.newLine : line.oldLine;
            const lineKey = `${fileName}:${line.side}:${lineNum ?? lIdx}`;
            const isEditorOpen = activeEditorLineKey === lineKey;

            // Match anchored Muse finding on this line
            const matchingFinding = anchoredFindings.find((f) => {
              if (line.side === 'RIGHT') {
                return f.lineHint !== null && f.lineHint === line.newLine;
              }
              return f.lineHint !== null && f.lineHint === line.oldLine;
            });

            // Match saved local draft on this line
            const matchingDraft = fileDrafts.find((d) => {
              if (line.side === 'RIGHT') {
                return d.line !== null && d.line === line.newLine && d.side === 'RIGHT';
              }
              return d.line !== null && d.line === line.oldLine && d.side === 'LEFT';
            });

            const isAdd = line.type === 'addition';
            const isDel = line.type === 'deletion';
            const isHunk = line.type === 'header';

            if (isHunk) {
              return (
                <tr key={lIdx} className="diff-hunk bg-[#f0f4f8] text-[#57606a] border-y border-[#d0d7de] font-bold">
                  <td colSpan={5} className="px-4 py-1 text-xs select-none">
                    {line.content}
                  </td>
                </tr>
              );
            }

            return (
              <React.Fragment key={lIdx}>
                {/* Diff Row */}
                <tr
                  className={`group border-b border-slate-100 ${
                    isAdd ? 'diff-added bg-[#e6ffec]' : isDel ? 'diff-removed bg-[#ffebe9]' : 'diff-context bg-white dark:bg-[#141821]'
                  }`}
                >
                  {/* Old Line Number */}
                  <td
                    className={`diff-number px-2 py-0.5 text-right select-none text-[11px] border-r border-[#d0d7de]/60 font-mono ${
                      isDel ? 'bg-[#ffdce0] text-[#cf222e]' : 'text-[#656d76]'
                    }`}
                  >
                    {line.oldLine ?? ''}
                  </td>

                  {/* New Line Number */}
                  <td
                    className={`diff-number px-2 py-0.5 text-right select-none text-[11px] border-r border-[#d0d7de]/60 font-mono ${
                      isAdd ? 'bg-[#dafbe1] text-[#1a7f37]' : 'text-[#656d76]'
                    }`}
                  >
                    {line.newLine ?? ''}
                  </td>

                  {/* Action Gutter: GitHub-Style Blue '+' Button */}
                  <td className="w-7 px-1 py-0.5 text-center select-none border-r border-[#d0d7de]/60">
                    {line.isCommentable && lineNum !== null ? (
                      <button
                        type="button"
                        aria-label={`Add comment on line ${lineNum}`}
                        title={`Add inline comment on line ${lineNum}`}
                        onClick={() =>
                          isEditorOpen
                            ? closeEditor()
                            : openEditorForLine(
                                lineKey,
                                matchingDraft?.comment || matchingFinding?.comment || ''
                              )
                        }
                        className={`w-5 h-5 rounded inline-flex items-center justify-center transition ${
                          matchingDraft
                            ? 'bg-accent-600 text-white shadow-2xs'
                            : matchingFinding
                            ? 'bg-[#0969da] text-white shadow-2xs'
                            : 'text-slate-400 opacity-50 group-hover:opacity-100 hover:bg-[#0969da] hover:text-white focus:opacity-100 focus:bg-[#0969da] focus:text-white'
                        }`}
                      >
                        {matchingDraft ? (
                          <MessageSquare className="w-3 h-3" />
                        ) : (
                          <Plus className="w-3.5 h-3.5" />
                        )}
                      </button>
                    ) : null}
                  </td>

                  {/* Sign Marker (+ or -) */}
                  <td
                    className={`diff-sign text-center select-none font-bold font-mono text-[13px] ${
                      isAdd ? 'text-[#1a7f37]' : isDel ? 'text-[#cf222e]' : 'text-transparent'
                    }`}
                  >
                    {isAdd ? '+' : isDel ? '-' : ' '}
                  </td>

                  {/* Code Content */}
                  <td className="diff-code px-2 py-0.5 whitespace-pre font-mono text-xs overflow-x-auto text-[#1f2328]">
                    {line.content}
                  </td>
                </tr>

                {/* ================= CODERABBIT-STYLE BOT FINDING CARD ================= */}
                {matchingFinding && (
                  <FindingCard
                    finding={matchingFinding}
                    headSha={headSha}
                    matchingDraft={matchingDraft}
                    isEditorOpen={isEditorOpen}
                    lineKey={lineKey}
                    openEditorForLine={openEditorForLine}
                    closeEditor={closeEditor}
                  />
                )}

                {/* ================= GITHUB-STYLE INLINE COMMENT EDITOR ================= */}
                {isEditorOpen && lineNum !== null && (
                  <tr>
                    <td colSpan={5} className="p-3 bg-[#f6f8fa] border-y border-[#d0d7de]">
                      <InlineCommentEditor
                        fileName={fileName}
                        lineNum={lineNum}
                        side={line.side}
                        initialText={matchingDraft?.comment || matchingFinding?.comment || ''}
                        isSavingDraft={isSavingDraft}
                        onSave={(lineNum, side, text) => onSaveDraft(fileName, lineNum, side, text)}
                        onClose={closeEditor}
                      />
                    </td>
                  </tr>
                )}

                {/* Saved Local Draft Banner */}
                {matchingDraft && (
                  <tr>
                    <td colSpan={5} className="p-3 bg-accent-50/70 border-y border-accent-200 dark:bg-accent-950/40/70 dark:border-accent-800">
                      <InlineDraft draft={matchingDraft} onEdit={() => openEditorForLine(lineKey, matchingDraft.comment)} onDelete={onDeleteDraft} />
                    </td>
                  </tr>
                )}
              </React.Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
