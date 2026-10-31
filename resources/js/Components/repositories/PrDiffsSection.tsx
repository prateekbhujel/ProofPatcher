import React from 'react';
import { Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { DiffViewer, PatchFile } from '../DiffViewer';
import { ChangedFile, ReviewFinding, CommentDraft } from './types';

interface PrDiffsSectionProps {
  files: ChangedFile[];
  patchPreview?: {
    headSha: string;
    baseSha: string;
    isPrivate?: boolean;
    changedFiles: number;
    filesTruncated: boolean;
    files: Array<{ name: string; status: 'available' | 'missing' | 'oversize' | 'partial'; patch: string | null }>;
  } | null;
  pullHeadSha: string;
  findings?: ReviewFinding[];
  drafts?: CommentDraft[];
  draftProcessing?: boolean;
  snapshotId?: string;
  onSaveDraft: (path: string, line: number | null, side: string, comment: string) => void;
  onDeleteDraft?: (draftId: string) => void;
}

export function PrDiffsSection({
  files,
  patchPreview,
  pullHeadSha,
  findings = [],
  drafts = [],
  draftProcessing = false,
  snapshotId,
  onSaveDraft,
  onDeleteDraft,
}: PrDiffsSectionProps) {
  if (patchPreview && patchPreview.files) {
    return (
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Diffs & File Findings ({patchPreview.files.length} files)
          </h3>
          {drafts.length > 0 && (
            <span className="text-xs font-medium text-slate-600 bg-slate-100 px-2.5 py-0.5 rounded-full dark:bg-slate-800 dark:text-slate-400">
              {drafts.length} saved local draft{drafts.length === 1 ? '' : 's'}
            </span>
          )}
        </div>

        <div className="space-y-4">
          {patchPreview.files.map((file) => {
            const fileFromPull = files.find((f) => f.name === file.name);
            const patchFile: PatchFile = {
              name: file.name,
              status: file.status,
              additions: fileFromPull?.additions,
              deletions: fileFromPull?.deletions,
              patch: file.patch,
            };

            return (
              <DiffViewer
                key={file.name}
                file={patchFile}
                headSha={pullHeadSha}
                findings={findings}
                drafts={drafts}
                initiallyExpanded={true}
                onSaveDraft={onSaveDraft}
                onDeleteDraft={onDeleteDraft}
                isSavingDraft={draftProcessing}
              />
            );
          })}
        </div>
      </div>
    );
  }

  if (!patchPreview) {
    return (
      <div className="p-8 text-center rounded-xl border border-slate-200 bg-slate-50/50 space-y-3 dark:bg-slate-900/50 dark:border-slate-800">
        <FileText className="w-8 h-8 text-slate-400 mx-auto" />
        <div className="space-y-1">
          <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Public Patch Preview Required</h3>
          <p className="text-xs text-slate-500 max-w-md mx-auto dark:text-slate-400">
            ProofPatcher needs to capture and verify the public patch diff locally before preparing the review payload.
          </p>
        </div>
        {snapshotId && (
          <Link
            href={`/repositories?snapshot=${encodeURIComponent(snapshotId)}&preview=1`}
            className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-medium rounded-md text-white bg-accent-700 hover:bg-accent-800 shadow-xs transition"
          >
            <span>Load Public Patch Preview</span>
          </Link>
        )}
      </div>
    );
  }

  return null;
}
