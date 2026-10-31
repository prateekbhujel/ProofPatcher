import React, { useState } from 'react';
import {
  ChevronDown,
  ChevronRight,
  FileText,
  Copy,
  Check,
  Columns,
  AlignJustify,
  AlertTriangle,
} from 'lucide-react';
import type {
  ReviewFinding,
  CommentDraft,
  PatchFile,
  DiffLine,
  SplitDiffRow,
  DiffViewerProps,
} from './diff/types';
import { parseDiffLines, parseSplitDiffRows } from './diff/diffParser';
import { copyText } from './ui/copyText';
import { UnifiedDiffTable } from './diff/UnifiedDiffTable';
import { SplitDiffTable } from './diff/SplitDiffTable';

export type {
  ReviewFinding,
  CommentDraft,
  PatchFile,
  DiffLine,
  SplitDiffRow,
  DiffViewerProps,
};

export { parseDiffLines, parseSplitDiffRows };

export const DiffViewer: React.FC<DiffViewerProps> = ({
  file,
  headSha,
  findings = [],
  drafts = [],
  initiallyExpanded = true,
  onSaveDraft,
  onDeleteDraft,
  isSavingDraft = false,
}) => {
  const [isExpanded, setIsExpanded] = useState(initiallyExpanded);
  const [diffMode, setDiffMode] = useState<'unified' | 'split'>('unified');
  const [isViewed, setIsViewed] = useState(false);
  const [copiedPath, setCopiedPath] = useState(false);

  // Active inline editor state
  const [activeEditorLineKey, setActiveEditorLineKey] = useState<string | null>(null);

  const fileFindings = findings.filter((f) => f.path === file.name);
  const fileDrafts = drafts.filter((d) => d.path === file.name);

  const parsedLines = file.patch ? parseDiffLines(file.patch) : [];
  const splitRows = file.patch ? parseSplitDiffRows(parsedLines) : [];

  const commentableNewLines = parsedLines
    .filter((l) => l.isCommentable && l.newLine !== null)
    .map((l) => l.newLine as number);
  const commentableOldLines = parsedLines
    .filter((l) => l.isCommentable && l.oldLine !== null)
    .map((l) => l.oldLine as number);

  // Separate anchored Muse findings from unanchored model hints
  const anchoredFindings = fileFindings.filter(
    (f) =>
      f.commentable === true ||
      (f.lineHint !== null && commentableNewLines.includes(f.lineHint))
  );

  const unanchoredFindings = fileFindings.filter(
    (f) =>
      f.lineHint === null ||
      (!commentableNewLines.includes(f.lineHint) && !commentableOldLines.includes(f.lineHint))
  );

  const copyFilePath = (e: React.MouseEvent) => {
    e.stopPropagation();
    void copyText(file.name).then((ok) => {
      if (!ok) return;
      setCopiedPath(true);
      setTimeout(() => setCopiedPath(false), 2000);
    });
  };

  const openEditorForLine = (lineKey: string, defaultText: string = '') => {
    setActiveEditorLineKey(lineKey);
  };

  const closeEditor = () => {
    setActiveEditorLineKey(null);
  };

  const handleSave = (fileName: string, lineNum: number, side: string, text: string) => {
    onSaveDraft(fileName, lineNum, side, text);
    closeEditor();
  };

  return (
    <div
      className={`diff-viewer border rounded-lg overflow-hidden bg-white shadow-2xs transition-colors ${
        isViewed ? 'border-slate-200/60 opacity-80 dark:border-slate-800/60' : 'border-slate-300 dark:border-slate-700'
      }`}
    >
      {/* File Header Bar — Exact GitHub PR Style */}
      <div className="diff-file-header w-full p-2.5 sm:px-4 bg-[#f6f8fa] border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 text-xs select-none dark:border-slate-800">
        <div className="flex items-center gap-2 min-w-0">
          <button
            type="button"
            onClick={() => setIsExpanded(!isExpanded)}
            aria-label={isExpanded ? 'Collapse file' : 'Expand file'}
            className="text-slate-500 hover:text-slate-800 p-0.5 rounded transition dark:text-slate-400"
          >
            {isExpanded ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
          </button>

          <FileText className="w-4 h-4 text-slate-500 shrink-0 dark:text-slate-400" />

          <span className="diff-file-name font-mono font-semibold text-[#1f2328] truncate text-[13px]">{file.name}</span>

          <button
            type="button"
            onClick={copyFilePath}
            title="Copy path"
            aria-label={`Copy path ${file.name}`}
            className="p-1 text-slate-400 hover:text-slate-700 rounded transition"
          >
            {copiedPath ? <Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" /> : <Copy className="w-3 h-3" />}
          </button>

          <span className="text-slate-400 text-2xs uppercase font-medium">({file.status})</span>
        </div>

        <div className="flex items-center gap-3">
          {/* Additions / Deletions Diff Stat with Colored Blocks */}
          <div className="flex items-center gap-1.5 font-mono text-[11px] font-semibold">
            {file.additions !== undefined && file.additions > 0 && (
              <span className="text-[#1a7f37]">+{file.additions}</span>
            )}
            {file.deletions !== undefined && file.deletions > 0 && (
              <span className="text-[#cf222e]">-{file.deletions}</span>
            )}
            <div className="flex items-center gap-0.5">
              {Array.from({ length: 5 }).map((_, i) => {
                const total = (file.additions || 0) + (file.deletions || 0);
                if (total === 0) return <span key={i} className="w-1.5 h-2 rounded-2xs bg-slate-200 dark:bg-slate-700" />;
                const addRatio = (file.additions || 0) / total;
                const addBlocks = Math.round(addRatio * 5);
                const isAdd = i < addBlocks;
                return (
                  <span
                    key={i}
                    className={`w-1.5 h-2 rounded-2xs ${isAdd ? 'bg-[#1a7f37]' : 'bg-[#cf222e]'}`}
                  />
                );
              })}
            </div>
          </div>

          {/* Unified / Split Toggle */}
          <div className="hidden sm:inline-flex items-center p-0.5 bg-slate-200/70 rounded-md border border-slate-300 text-[11px] dark:bg-slate-700/70 dark:border-slate-700">
            <button
              type="button"
              onClick={() => setDiffMode('unified')}
              title="Unified diff"
              className={`px-2 py-0.5 rounded transition font-medium flex items-center gap-1 ${
                diffMode === 'unified'
                  ? 'bg-white text-slate-900 shadow-2xs font-semibold dark:bg-[#141821] dark:text-slate-100'
                  : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100'
              }`}
            >
              <AlignJustify className="w-3 h-3" />
              <span>Unified</span>
            </button>
            <button
              type="button"
              onClick={() => setDiffMode('split')}
              title="Split diff"
              className={`px-2 py-0.5 rounded transition font-medium flex items-center gap-1 ${
                diffMode === 'split'
                  ? 'bg-white text-slate-900 shadow-2xs font-semibold dark:bg-[#141821] dark:text-slate-100'
                  : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100'
              }`}
            >
              <Columns className="w-3 h-3" />
              <span>Split</span>
            </button>
          </div>

          {/* Viewed Checkbox */}
          <label className="flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none dark:text-slate-400">
            <input
              type="checkbox"
              checked={isViewed}
              onChange={(e) => setIsViewed(e.target.checked)}
              className="rounded border-slate-300 text-accent-600 focus:ring-accent-500 w-3.5 h-3.5 dark:border-slate-700"
            />
            <span className="font-medium text-[11px]">Viewed</span>
          </label>
        </div>
      </div>

      {/* Expanded Diff Body */}
      {isExpanded && (
        <div className="space-y-0">
          {/* Unanchored Muse Hints Warning if any */}
          {unanchoredFindings.length > 0 && (
            <div className="p-3 bg-amber-50/70 border-b border-amber-200 text-xs space-y-1.5 dark:bg-amber-950/40/70 dark:border-amber-800">
              <div className="flex items-center gap-2 text-amber-900 font-semibold dark:text-amber-200">
                <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 dark:text-amber-400" />
                <span>Muse Advisory Hint (Outside Captured Diff Hunks)</span>
              </div>
              <div className="space-y-1 pt-0.5">
                {unanchoredFindings.map((uf, uIdx) => (
                  <div
                    key={uIdx}
                    className="p-2 rounded bg-white/80 border border-amber-200 text-slate-700 text-xs space-y-0.5 dark:bg-[#141821]/80 dark:border-amber-800 dark:text-slate-300"
                  >
                    <div className="flex items-center gap-2 font-mono text-[11px]">
                      <span className="font-semibold text-amber-900 dark:text-amber-200">Line Hint: {uf.lineHint ?? 'None'}</span>
                      <span className="uppercase text-2xs px-1.5 py-0.2 rounded bg-amber-100 text-amber-800 font-bold dark:text-amber-300">
                        {uf.status}
                      </span>
                    </div>
                    <p className="text-slate-800 dark:text-slate-200">{uf.comment}</p>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Render Diff Lines or Unavailable State */}
          {!file.patch ? (
            <div className="p-6 text-center text-xs text-slate-500 bg-slate-50/50 dark:bg-slate-900/50 dark:text-slate-400">
              {file.status === 'oversize'
                ? 'Patch exceeds preview size limits.'
                : 'Unified patch diff not available for this file (file may be binary or unmodified).'}
            </div>
          ) : diffMode === 'split' ? (
            <SplitDiffTable
              fileName={file.name}
              splitRows={splitRows}
              fileDrafts={fileDrafts}
              activeEditorLineKey={activeEditorLineKey}
              openEditorForLine={openEditorForLine}
              closeEditor={closeEditor}
              onSaveDraft={handleSave}
              onDeleteDraft={onDeleteDraft}
              isSavingDraft={isSavingDraft}
            />
          ) : (
            <UnifiedDiffTable
              fileName={file.name}
              headSha={headSha}
              parsedLines={parsedLines}
              anchoredFindings={anchoredFindings}
              fileDrafts={fileDrafts}
              activeEditorLineKey={activeEditorLineKey}
              openEditorForLine={openEditorForLine}
              closeEditor={closeEditor}
              onSaveDraft={handleSave}
              onDeleteDraft={onDeleteDraft}
              isSavingDraft={isSavingDraft}
            />
          )}
        </div>
      )}
    </div>
  );
};
