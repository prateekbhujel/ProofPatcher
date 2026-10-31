import React from 'react';
import { Edit3, Trash2 } from 'lucide-react';
import { CommentDraft } from './types';

export function InlineDraft({ draft, onEdit, onDelete }: {
  draft: CommentDraft;
  onEdit: () => void;
  onDelete?: (id: string) => void;
}) {
  return (
    <div className="p-3 rounded-lg bg-white border border-accent-200 shadow-2xs space-y-2 font-sans">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="text-2xs font-bold font-mono px-2 py-0.5 rounded bg-accent-100 text-accent-900 border border-accent-300">
            Saved Local Draft · Line {draft.line} ({draft.side})
          </span>
          {draft.updatedAt && <span className="text-2xs text-slate-500 font-mono">{new Date(draft.updatedAt).toLocaleTimeString()}</span>}
        </div>
        <div className="flex items-center gap-3">
          <button type="button" onClick={onEdit} className="inline-flex items-center gap-1 text-xs font-medium text-slate-700 hover:text-slate-900 underline">
            <Edit3 className="w-3 h-3" /> Edit
          </button>
          {onDelete && (
            <button type="button" onClick={() => onDelete(draft.id)} className="inline-flex items-center gap-1 text-xs font-medium text-rose-600 hover:text-rose-800 underline">
              <Trash2 className="w-3 h-3" /> Delete
            </button>
          )}
        </div>
      </div>
      <p className="text-xs text-slate-800 leading-relaxed font-normal whitespace-pre-wrap">{draft.comment}</p>
    </div>
  );
}
