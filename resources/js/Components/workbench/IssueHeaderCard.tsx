import React from 'react';
import { ExternalLink } from 'lucide-react';
import { IssueCompleteness, IssueContext } from './types';

interface IssueMedia {
  alt: string;
  url: string;
  isGif: boolean;
}

interface IssueHeaderCardProps {
  context: IssueContext;
  isDedicatedTestRepo: boolean;
  isOwnedFixture: boolean;
  detectedIssueMedia: IssueMedia[];
  completeness?: IssueCompleteness | null;
}

const VERDICT_COPY: Record<IssueCompleteness['verdict'], string> = {
  complete: 'Complete report',
  needs_info: 'Needs info',
  empty: 'Empty report',
};

export function IssueHeaderCard({ context, isDedicatedTestRepo, isOwnedFixture, detectedIssueMedia, completeness }: IssueHeaderCardProps) {
  const isExample = !isDedicatedTestRepo && (isOwnedFixture || context.isSynthetic);

  return (
    <section aria-label="Issue details" className="space-y-3">
      <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
        <span>{isExample ? 'Example issue' : 'GitHub issue'}</span>
        {context.issueUrl && (
          <a href={context.issueUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-accent-700 hover:underline dark:text-accent-300">
            #{context.issueNumber} on GitHub <ExternalLink aria-hidden="true" className="h-3 w-3" />
          </a>
        )}
      </div>
      <h1 className="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{context.title}</h1>
      <div className="max-h-60 overflow-y-auto whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-600 dark:text-slate-300" tabIndex={0} aria-label="Issue description">
        {context.body || 'No description provided.'}
      </div>

      {completeness && (
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span
            className={`px-2 py-0.5 rounded-full font-semibold border ${
              completeness.verdict === 'complete'
                ? 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-800'
                : 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-800'
            }`}
          >
            {VERDICT_COPY[completeness.verdict]} · {completeness.score}
          </span>
          <details className="text-slate-500 dark:text-slate-400">
            <summary className="cursor-pointer hover:underline">
              {completeness.verdict === 'complete'
                ? 'What was checked'
                : `Missing: ${completeness.missing.slice(0, 3).join(', ') || 'details'}`}
            </summary>
            <ul className="mt-2 space-y-1">
              {completeness.checks.map((check) => (
                <li key={check.key} className="flex items-start gap-1.5">
                  <span aria-hidden="true" className={check.passed ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'}>
                    {check.passed ? '✓' : '○'}
                  </span>
                  <span>
                    <span className="font-medium text-slate-700 dark:text-slate-200">{check.label}.</span>{' '}
                    {check.detail}
                  </span>
                </li>
              ))}
            </ul>
            {completeness.questions.length > 0 && (
              <div className="mt-2">
                <div className="font-medium text-slate-700 dark:text-slate-200">Worth asking the reporter:</div>
                <ul className="mt-1 list-disc space-y-0.5 pl-5">
                  {completeness.questions.map((question) => (
                    <li key={question}>{question}</li>
                  ))}
                </ul>
              </div>
            )}
          </details>
        </div>
      )}

      {detectedIssueMedia.length > 0 && (
        <details className="text-sm text-slate-600 dark:text-slate-300">
          <summary className="cursor-pointer font-medium">Attachments ({detectedIssueMedia.length})</summary>
          <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Muse cannot read these attachments yet.</p>
          <ul className="mt-2 space-y-1">
            {detectedIssueMedia.map((media, index) => (
              <li key={`${media.url}-${index}`}>
                <a href={media.url} target="_blank" rel="noreferrer" className="break-all text-accent-700 hover:underline dark:text-accent-300">
                  {media.alt || (media.isGif ? 'GIF' : 'Image')}
                </a>
              </li>
            ))}
          </ul>
        </details>
      )}

      <details className="rounded-lg border border-slate-200 px-3 py-2 text-xs dark:border-slate-800">
        <summary className="cursor-pointer font-medium text-slate-700 dark:text-slate-300">View source file</summary>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-slate-500 dark:text-slate-400">
          <code className="break-all">{context.path}</code>
          <span className="break-all font-mono">SHA-256: {context.sourceSha256}</span>
        </div>
        <pre className="mt-2 max-h-52 overflow-auto whitespace-pre rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-800 dark:bg-slate-950 dark:text-slate-200">{context.source}</pre>
      </details>
    </section>
  );
}
