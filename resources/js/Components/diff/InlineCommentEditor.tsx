import React, { useState } from 'react';
import { X, Bold, Italic, Code, Link2, List, FileText } from 'lucide-react';

interface InlineCommentEditorProps {
  fileName: string;
  lineNum: number;
  side: string;
  initialText?: string;
  isSavingDraft?: boolean;
  onSave: (lineNum: number, side: string, text: string) => void;
  onClose: () => void;
}

export function InlineCommentEditor({
  fileName,
  lineNum,
  side,
  initialText = '',
  isSavingDraft = false,
  onSave,
  onClose,
}: InlineCommentEditorProps) {
  const [editorText, setEditorText] = useState(initialText);
  const [editorTab, setEditorTab] = useState<'write' | 'preview'>('write');

  const insertMarkdown = (before: string, after: string = '') => {
    setEditorText((prev) => `${prev}${before}text${after}`);
  };

  const handleInsertTemplate = () => {
    const template = `### Review Note (${fileName}:${lineNum})\n\n- **Verification concern**:\n- **Edge case / Boundary**:\n- **Suggested change**:\n`;
    setEditorText((current) => current.trim() ? `${current}\n\n${template}` : template);
  };

  const handleSave = () => {
    if (!editorText.trim() || isSavingDraft) return;
    onSave(lineNum, side, editorText.trim());
  };

  return (
    <div className="rounded-lg bg-[var(--bg-raised)] border border-[var(--border)] shadow-xs font-sans overflow-hidden">
      {/* Top Header: Avatar + Title */}
      <div className="px-3.5 py-2 bg-[var(--bg-sunken)] border-b border-[var(--border)] flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="w-5 h-5 rounded-full bg-slate-700 text-white text-[10px] font-bold flex items-center justify-center">
            P
          </div>
          <span className="text-xs font-semibold text-[var(--fg)]">
            Add a comment on line {lineNum} ({side === 'RIGHT' ? 'HEAD' : 'BASE'})
          </span>
        </div>
        <button
          type="button"
          aria-label="Close comment editor"
          onClick={onClose}
          className="text-[var(--fg-muted)] hover:text-[var(--fg)]"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      {/* Tab Controls: Write | Preview */}
      <div className="px-3.5 pt-2 flex items-center justify-between border-b border-slate-100 dark:border-slate-800">
        <div className="flex space-x-2">
          <button
            type="button"
            onClick={() => setEditorTab('write')}
            className={`px-3 py-1.5 text-xs font-medium border-b-2 transition ${
              editorTab === 'write'
                ? 'border-accent-500 text-[var(--fg)] font-semibold'
                : 'border-transparent text-[var(--fg-muted)] hover:text-[var(--fg)]'
            }`}
          >
            Write
          </button>
          <button
            type="button"
            onClick={() => setEditorTab('preview')}
            className={`px-3 py-1.5 text-xs font-medium border-b-2 transition ${
              editorTab === 'preview'
                ? 'border-accent-500 text-[var(--fg)] font-semibold'
                : 'border-transparent text-[var(--fg-muted)] hover:text-[var(--fg)]'
            }`}
          >
            Preview
          </button>
        </div>

        {/* GitHub Markdown Toolbar */}
        {editorTab === 'write' && (
          <div className="flex items-center gap-1 text-slate-500 pb-1 dark:text-slate-400">
            <button
              type="button"
              onClick={() => insertMarkdown('**', '**')}
              title="Bold"
              className="p-1 hover:bg-slate-100 rounded dark:hover:bg-slate-700"
            >
              <Bold className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={() => insertMarkdown('_', '_')}
              title="Italic"
              className="p-1 hover:bg-slate-100 rounded dark:hover:bg-slate-700"
            >
              <Italic className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={() => insertMarkdown('`', '`')}
              title="Code"
              className="p-1 hover:bg-slate-100 rounded dark:hover:bg-slate-700"
            >
              <Code className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={() => insertMarkdown('[', '](url)')}
              title="Link"
              className="p-1 hover:bg-slate-100 rounded dark:hover:bg-slate-700"
            >
              <Link2 className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={() => insertMarkdown('- ')}
              title="List"
              className="p-1 hover:bg-slate-100 rounded dark:hover:bg-slate-700"
            >
              <List className="w-3.5 h-3.5" />
            </button>
          </div>
        )}
      </div>

      {/* Editor Body */}
      <div className="p-3">
        {editorTab === 'write' ? (
          <textarea
            autoFocus
            rows={4}
            value={editorText}
            onChange={(e) => setEditorText(e.target.value)}
            placeholder="Leave a comment... Markdown supported"
            aria-label={`Comment on line ${lineNum}`}
            className="w-full text-xs font-sans rounded-md border border-[var(--border-strong)] bg-[var(--bg-sunken)] p-2.5 text-[var(--fg)] focus:outline-none focus:ring-2 focus:ring-accent-500 leading-relaxed"
          />
        ) : (
          <div className="min-h-[96px] p-2.5 rounded-md bg-[var(--bg-sunken)] border border-[var(--border)] text-xs text-[var(--fg)] whitespace-pre-wrap font-sans">
            {editorText.trim() ? editorText : <span className="text-slate-400 italic">Nothing to preview</span>}
          </div>
        )}
      </div>

      {/* Bottom Action Bar */}
      <div className="px-3 py-2.5 bg-[var(--bg-sunken)] border-t border-[var(--border)] flex flex-wrap items-center justify-between gap-2">
        {/* Review Template Action */}
        <button
          type="button"
          onClick={handleInsertTemplate}
          className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-300 transition dark:bg-slate-800 dark:border-slate-700 dark:text-slate-300"
          title="Insert a structured review comment template"
        >
          <FileText className="w-3.5 h-3.5 text-slate-600 dark:text-slate-400" />
          <span>Insert Review Template</span>
        </button>

        {/* Cancel and Save Draft Buttons */}
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={onClose}
            className="px-3 py-1.5 text-xs font-medium rounded-md text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 transition dark:bg-[#141821] dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
          >
            Cancel
          </button>
          <button
            type="button"
            disabled={!editorText.trim() || isSavingDraft}
            onClick={handleSave}
            className="px-3.5 py-1.5 text-xs font-semibold rounded-md text-white bg-[#1f883d] hover:bg-[#1a7f37] disabled:opacity-40 transition shadow-2xs"
          >
            {isSavingDraft ? 'Saving...' : 'Save draft'}
          </button>
        </div>
      </div>
    </div>
  );
}
