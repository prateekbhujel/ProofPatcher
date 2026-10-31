import React from 'react';
import { Link } from '@inertiajs/react';
import { Search, AlertTriangle, MessageSquare } from 'lucide-react';
import { DiffViewer, PatchFile } from '../DiffViewer';
import { ChangedFile, SnapshotData, ReviewFinding, CommentDraft } from './types';

interface PrFilesTabProps {
  filteredFiles: ChangedFile[];
  fileQuery: string;
  setFileQuery: (query: string) => void;
  patchPreview?: {
    headSha: string;
    baseSha: string;
    isPrivate?: boolean;
    changedFiles: number;
    filesTruncated: boolean;
    files: Array<{ name: string; status: 'available' | 'missing' | 'oversize' | 'partial'; patch: string | null }>;
  } | null;
  snapshot?: SnapshotData | null;
  patchPreviewError?: string | null;
  headSha: string;
  findings?: ReviewFinding[];
  drafts?: CommentDraft[];
  draftProcessing?: boolean;
  onSaveDraft: (path: string, line: number | null, side: string, comment: string) => void;
  onDeleteDraft?: (draftId: string) => void;
}

export function PrFilesTab({
  filteredFiles,
  fileQuery,
  setFileQuery,
  patchPreview,
  snapshot,
  patchPreviewError,
  headSha,
  findings = [],
  drafts = [],
  draftProcessing = false,
  onSaveDraft,
  onDeleteDraft,
}: PrFilesTabProps) {
  return (
    <div className="bg-white rounded-xl border border-slate-200/80 shadow-xs p-6 space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-slate-900">Changed Files</h2>
          <p className="text-xs text-slate-500">
            Click any file row to expand its unified diff, inspect inline findings, and draft local line comments.
          </p>
        </div>
        <div className="relative">
          <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            placeholder="Filter files..."
            value={fileQuery}
            onChange={(e) => setFileQuery(e.target.value)}
            className="pl-8 pr-3 py-1.5 text-xs rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-accent-500 shadow-2xs"
          />
        </div>
      </div>

      <div className="flex items-center justify-between p-3 rounded-lg bg-accent-50/70 dark:bg-accent-950/40 border border-accent-200 dark:border-accent-800 text-xs text-accent-900 dark:text-accent-200">
        <div className="flex items-center gap-2">
          <MessageSquare className="w-4 h-4 text-accent-700 dark:text-accent-400 shrink-0" />
          <span><strong>Inline Comments:</strong> Click the <strong>+</strong> button on any diff line to add maintainer review comments. Drafts stay local until published.</span>
        </div>
      </div>

      {!patchPreview && snapshot && (
        <div className="p-4 rounded-lg bg-slate-50 border border-slate-200 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="space-y-0.5">
            <p className="font-semibold text-slate-800">Unified diffs and inline commenting require patch preview</p>
            <p className="text-slate-500 text-[11px]">
              Load public patch diffs to expand files, view Muse findings, and draft maintainer comments.
            </p>
          </div>
          <Link
            href={`/repositories?snapshot=${encodeURIComponent(snapshot.id)}&preview=1`}
            className="shrink-0 px-3 py-1.5 text-xs font-medium rounded-md text-white bg-accent-700 hover:bg-accent-800 shadow-xs transition"
          >
            Load Public Patch Preview
          </Link>
        </div>
      )}

      {patchPreviewError && (
        <div role="alert" className="p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
          <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
          <span>{patchPreviewError}</span>
        </div>
      )}

      {patchPreview?.filesTruncated && (
        <p className="text-xs text-amber-700 bg-amber-50 p-2.5 rounded border border-amber-200">
          GitHub returned only {patchPreview.files.length} of {patchPreview.changedFiles} changed files.
        </p>
      )}

      <div className="space-y-3">
        {filteredFiles.length === 0 ? (
          <div className="p-6 text-center text-xs text-slate-500 bg-slate-50 rounded-lg border border-slate-200">
            No matching files found.
          </div>
        ) : (
          filteredFiles.map((f) => {
            const patchForFile = patchPreview?.files.find((pf) => pf.name === f.name);
            const patchFile: PatchFile = {
              name: f.name,
              status: f.status || 'modified',
              additions: f.additions,
              deletions: f.deletions,
              patch: patchForFile ? patchForFile.patch : null,
            };

            return (
              <DiffViewer
                key={f.name}
                file={patchFile}
                headSha={headSha}
                findings={findings}
                drafts={drafts}
                initiallyExpanded={filteredFiles.length <= 3}
                onSaveDraft={onSaveDraft}
                onDeleteDraft={onDeleteDraft}
                isSavingDraft={draftProcessing}
              />
            );
          })
        )}
      </div>
    </div>
  );
}
