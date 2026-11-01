import React, { useState } from 'react';
import { router } from '@inertiajs/react';

interface WorkbenchScreenshotProps {
  hasScreenshot: boolean;
}

/** Local repro screenshot for the active issue. Stored with the task, never sent to a model. */
export function WorkbenchScreenshot({ hasScreenshot }: WorkbenchScreenshotProps) {
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  function onFileSelected(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.type !== 'image/png' && file.type !== 'image/jpeg') {
      setUploadError(`“${file.name}” can't attach: use a PNG or JPEG image.`);
      return;
    }
    setUploadError(null);
    setUploading(true);
    router.post('/issues/screenshot', { image: file }, { onFinish: () => setUploading(false) });
  }

  return (
    <section aria-label="Issue screenshot" className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
      <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-100">Repro screenshot</h3>
      <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
        Saved locally with this task. Screenshots are never sent to a model.
      </p>
      {hasScreenshot ? (
        <a href="/issues/screenshot" target="_blank" rel="noreferrer" className="mt-3 block">
          <img
            src="/issues/screenshot"
            alt="Repro screenshot for this issue"
            className="max-h-64 rounded-md border border-slate-200 object-contain dark:border-slate-700"
          />
        </a>
      ) : (
        <div className="mt-3">
          <label
            htmlFor="issue-screenshot"
            className="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-700 disabled:opacity-40"
          >
            {uploading ? 'Uploading…' : 'Attach screenshot'}
          </label>
          <input
            id="issue-screenshot"
            type="file"
            accept="image/png,image/jpeg"
            onChange={onFileSelected}
            disabled={uploading}
            className="sr-only"
          />
          {uploadError && <p className="mt-2 text-xs text-red-700 dark:text-red-400">{uploadError}</p>}
        </div>
      )}
    </section>
  );
}
