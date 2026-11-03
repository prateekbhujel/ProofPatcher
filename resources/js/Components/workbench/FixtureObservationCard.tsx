import React from 'react';
import { GitPullRequest, Shield } from 'lucide-react';
import { FixtureObservation, Suggestion } from './types';

interface Props {
  suggestion: Suggestion;
  observation: FixtureObservation | null;
  observing: boolean;
  publishing: boolean;
  publishedUrl?: string | null;
  onObserve: () => void;
  onPublish: () => void;
}

/** A finite fixture result is deliberately separate from a verified-run report. */
export function FixtureObservationCard({ suggestion, observation, observing, publishing, publishedUrl, onObserve, onPublish }: Props) {
  const current = observation?.scope === 'finite_local_fixture'
    && !!suggestion.candidate?.sha256
    && observation.candidateSha256 === suggestion.candidate.sha256;
  const observationsPassed = current && observation?.status === 'passed'
    && observation.baseline === 'fixture_failed' && observation.negative === 'fixture_failed'
    && observation.candidate === 'fixture_passed'
    && observation.calls && typeof observation.calls === 'object'
    && Object.values(observation.calls).length === 3
    && Object.values(observation.calls).every((calls) => Number.isInteger(calls) && calls > 0);
  const canPublish = observationsPassed && observation?.canPublish === true;

  return <section className="rounded-2xl border border-slate-200 bg-white p-5 space-y-4 dark:border-slate-800 dark:bg-[#141821]">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100"><Shield className="h-4 w-4" /> Check this change</h3>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Compare this patch against the included order-total cases in isolated sandboxes.</p>
      </div>
      <button type="button" onClick={onObserve} disabled={observing || publishing} className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900">
        {observing ? 'Running checks…' : observation ? 'Run checks again' : 'Run checks'}
      </button>
    </div>
    {current && observation && <div className="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-700" role="status">
      <h4 className="text-sm font-semibold text-slate-900 dark:text-slate-100">{observationsPassed ? 'Fixture checks passed' : observation.status === 'failed' ? 'Fixture checks failed' : 'Checks could not be confirmed'}</h4>
      <ul className="space-y-1 text-sm text-slate-600 dark:text-slate-300">
        <li>Original: {observation.baseline === 'fixture_failed' ? 'issue reproduced' : 'not confirmed'}</li>
        <li>Negative control: {observation.negative === 'fixture_failed' ? 'rejected' : 'not confirmed'}</li>
        <li>Proposed change: {observation.candidate === 'fixture_passed' ? 'matched the test cases' : 'did not pass'}</li>
      </ul>
      <p className="text-xs text-slate-500 dark:text-slate-400">These observations cover the included fixture cases only. They do not prove the patch is generally correct or safe.</p>
      {!publishedUrl && canPublish && <button type="button" disabled={publishing || observing} onClick={onPublish} className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900"><GitPullRequest className="h-4 w-4" />{publishing ? 'Creating draft…' : 'Create draft PR'}</button>}
      {canPublish && !publishedUrl && <p className="text-xs text-slate-500 dark:text-slate-400">You will be asked to confirm before anything is sent to GitHub.</p>}
    </div>}
    {observation && !current && <p className="text-xs text-slate-500 dark:text-slate-400">The proposal changed. Run checks again before creating a draft.</p>}
    {publishedUrl && <a href={publishedUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 text-sm font-semibold text-blue-700 dark:text-blue-300"><GitPullRequest className="h-4 w-4" /> Open existing draft on GitHub</a>}
  </section>;
}
