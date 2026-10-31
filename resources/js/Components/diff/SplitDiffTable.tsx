import React from 'react';
import { Plus } from 'lucide-react';
import { CommentDraft, SplitDiffRow } from './types';
import { InlineCommentEditor } from './InlineCommentEditor';
import { InlineDraft } from './InlineDraft';

interface SplitDiffTableProps {
  fileName: string;
  splitRows: SplitDiffRow[];
  fileDrafts: CommentDraft[];
  activeEditorLineKey: string | null;
  openEditorForLine: (lineKey: string, defaultText?: string) => void;
  closeEditor: () => void;
  onSaveDraft: (fileName: string, lineNum: number, side: string, text: string) => void;
  onDeleteDraft?: (draftId: string) => void;
  isSavingDraft?: boolean;
}

export function SplitDiffTable({
  fileName,
  splitRows,
  fileDrafts,
  activeEditorLineKey,
  openEditorForLine,
  closeEditor,
  onSaveDraft,
  onDeleteDraft,
  isSavingDraft = false,
}: SplitDiffTableProps) {
  return (
    <div className="overflow-x-auto text-[12px] font-mono leading-5">
      <table className="w-full border-collapse table-fixed">
        <colgroup>
          <col className="w-10" />
          <col className="w-5" />
          <col className="w-[calc(50%-3.75rem)]" />
          <col className="w-10" />
          <col className="w-5" />
          <col className="w-[calc(50%-3.75rem)]" />
        </colgroup>
        <tbody>
          {splitRows.map((row, rIdx) => {
            if (row.type === 'header') {
              return (
                <tr key={rIdx} className="diff-hunk bg-[#f0f4f8] text-[#57606a] border-y border-[#d0d7de] font-bold">
                  <td colSpan={6} className="px-4 py-1 text-xs select-none">
                    {row.headerText}
                  </td>
                </tr>
              );
            }

            const leftDel = row.left?.type === 'deletion';
            const rightAdd = row.right?.type === 'addition';

            const leftLineKey = `${fileName}:LEFT:${row.left?.lineNum ?? rIdx}`;
            const rightLineKey = `${fileName}:RIGHT:${row.right?.lineNum ?? rIdx}`;
            const leftDraft = row.left?.lineNum == null ? undefined : fileDrafts.find((draft) => draft.side === 'LEFT' && draft.line === row.left?.lineNum);
            const rightDraft = row.right?.lineNum == null ? undefined : fileDrafts.find((draft) => draft.side === 'RIGHT' && draft.line === row.right?.lineNum);
            const isLeftEditorOpen = activeEditorLineKey === leftLineKey;
            const isRightEditorOpen = activeEditorLineKey === rightLineKey;

            return (
              <React.Fragment key={rIdx}>
                <tr className="border-b border-slate-100 group dark:border-slate-800">
                  {/* Left Line Number (Base) */}
                  <td
                    className={`diff-number px-2 py-0.5 text-right select-none text-[11px] border-r border-[#d0d7de]/60 ${
                      leftDel ? 'diff-removed bg-[#ffdce0] text-[#cf222e]' : 'bg-white text-[#656d76] dark:bg-[#141821]'
                    }`}
                  >
                    {row.left?.lineNum ?? ''}
                  </td>

                  {/* Left Sign Marker (-) */}
                  <td
                    className={`diff-sign text-center select-none font-bold ${
                      leftDel ? 'diff-removed bg-[#ffdce0] text-[#cf222e]' : 'bg-white text-transparent dark:bg-[#141821]'
                    }`}
                  >
                    {leftDel ? '-' : ' '}
                  </td>

                  {/* Left Code Content */}
                  <td
                    className={`diff-code px-2 py-0.5 whitespace-pre overflow-x-auto border-r border-[#d0d7de] text-[#1f2328] ${
                      leftDel ? 'diff-removed bg-[#ffebe9]' : 'bg-white dark:bg-[#141821]'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <span>{row.left?.content ?? ''}</span>
                      {row.left?.lineNum != null && (
                        <button type="button" aria-label={`Add comment on line ${row.left.lineNum} (base)`}
                          onClick={() => isLeftEditorOpen ? closeEditor() : openEditorForLine(leftLineKey, leftDraft?.comment)}
                          className="w-4 h-4 rounded inline-flex items-center justify-center opacity-50 group-hover:opacity-100 focus:opacity-100 bg-accent-600 text-white transition hover:bg-accent-700">
                          <Plus className="w-3.5 h-3.5" />
                        </button>
                      )}
                    </div>
                  </td>

                  {/* Right Line Number (Head) */}
                  <td
                    className={`diff-number px-2 py-0.5 text-right select-none text-[11px] border-r border-[#d0d7de]/60 ${
                      rightAdd ? 'diff-added bg-[#dafbe1] text-[#1a7f37]' : 'bg-white text-[#656d76] dark:bg-[#141821]'
                    }`}
                  >
                    {row.right?.lineNum ?? ''}
                  </td>

                  {/* Right Sign Marker (+) */}
                  <td
                    className={`diff-sign text-center select-none font-bold ${
                      rightAdd ? 'diff-added bg-[#dafbe1] text-[#1a7f37]' : 'bg-white text-transparent dark:bg-[#141821]'
                    }`}
                  >
                    {rightAdd ? '+' : ' '}
                  </td>

                  {/* Right Code Content */}
                  <td
                    className={`diff-code px-2 py-0.5 whitespace-pre overflow-x-auto text-[#1f2328] relative ${
                      rightAdd ? 'diff-added bg-[#e6ffec]' : 'bg-white dark:bg-[#141821]'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <span>{row.right?.content ?? ''}</span>
                      {row.right?.lineNum != null && (
                        <button
                          type="button"
                          aria-label={`Add comment on line ${row.right?.lineNum}`}
                          title={`Add inline comment on line ${row.right?.lineNum}`}
                          onClick={() =>
                            isRightEditorOpen ? closeEditor() : openEditorForLine(rightLineKey, rightDraft?.comment)
                          }
                          className="w-4 h-4 rounded inline-flex items-center justify-center opacity-50 group-hover:opacity-100 focus:opacity-100 bg-accent-600 text-white transition hover:bg-accent-700"
                        >
                          <Plus className="w-3.5 h-3.5" />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>

                {isLeftEditorOpen && row.left && row.left.lineNum !== null && (
                  <tr>
                    <td colSpan={6} className="p-3 bg-[#f6f8fa] border-y border-[#d0d7de]">
                      <InlineCommentEditor fileName={fileName} lineNum={row.left.lineNum} side="LEFT"
                        initialText={leftDraft?.comment || ''} isSavingDraft={isSavingDraft}
                        onSave={(lineNum, side, text) => onSaveDraft(fileName, lineNum, side, text)} onClose={closeEditor} />
                    </td>
                  </tr>
                )}
                {isRightEditorOpen && row.right && row.right.lineNum !== null && (
                  <tr>
                    <td colSpan={6} className="p-3 bg-[#f6f8fa] border-y border-[#d0d7de]">
                      <InlineCommentEditor
                        fileName={fileName}
                        lineNum={row.right.lineNum}
                        side="RIGHT"
                        initialText={rightDraft?.comment || ''}
                        isSavingDraft={isSavingDraft}
                        onSave={(lineNum, side, text) => onSaveDraft(fileName, lineNum, side, text)}
                        onClose={closeEditor}
                      />
                    </td>
                  </tr>
                )}
                {[leftDraft, rightDraft].filter((draft): draft is CommentDraft => Boolean(draft)).map((draft) => (
                  <tr key={draft.id}>
                    <td colSpan={6} className="p-3 bg-accent-50/70 border-y border-accent-200 dark:bg-accent-950/40/70 dark:border-accent-800">
                      <InlineDraft draft={draft} onEdit={() => openEditorForLine(draft.side === 'LEFT' ? leftLineKey : rightLineKey, draft.comment)} onDelete={onDeleteDraft} />
                    </td>
                  </tr>
                ))}
              </React.Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
