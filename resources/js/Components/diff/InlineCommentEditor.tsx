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
    setEditorText(template);
  };

  const handleSave = () => {
    if (!editorText.trim()) return;
    onSave(lineNum, side, editorText.trim());
  };

  return (
    <div className="rounded-lg bg-white border border-[#d0d7de] shadow-xs font-sans overflow-hidden dark:bg-[#141821]">
      {/* Top Header: Avatar + Title */}
      <div className="px-3.5 py-2 bg-[#f6f8fa] border-b border-[#d0d7de] flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="w-5 h-5 rounded-full bg-slate-700 text-white text-[10px] font-bold flex items-center justify-center">
            P
          </div>
          <span className="text-xs font-bold text-[#1f2328]">
            Add a comment on line {lineNum} ({side === 'RIGHT' ? 'HEAD' : 'BASE'})
          </span>
        </div>
        <button
          type="button"
          onClick={onClose}
          className="text-slate-400 hover:text-slate-700"
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
                ? 'border-[#0969da] text-[#1f2328] font-semibold'
                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400'
            }`}
          >
            Write
          </button>
          <button
            type="button"
            onClick={() => setEditorTab('preview')}
            className={`px-3 py-1.5 text-xs font-medium border-b-2 transition ${
              editorTab === 'preview'
                ? 'border-[#0969da] text-[#1f2328] font-semibold'
                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400'
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
            className="w-full text-xs font-sans rounded-md border border-slate-300 p-2.5 text-slate-900 focus:outline-none focus:ring-2 focus:ring-[#0969da] leading-relaxed dark:border-slate-700 dark:text-slate-100"
          />
        ) : (
          <div className="min-h-[96px] p-2.5 rounded-md bg-slate-50 border border-slate-200 text-xs text-slate-800 whitespace-pre-wrap font-sans dark:bg-slate-900 dark:border-slate-800 dark:text-slate-200">
            {editorText.trim() ? editorText : <span className="text-slate-400 italic">Nothing to preview</span>}
          </div>
        )}
      </div>

      {/* Bottom Action Bar */}
      <div className="px-3 py-2.5 bg-[#f6f8fa] border-t border-[#d0d7de] flex flex-wrap items-center justify-between gap-2">
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
            {isSavingDraft ? 'Saving...' : 'Add Review Comment'}
          </button>
        </div>
      </div>
    </div>
  );
}
