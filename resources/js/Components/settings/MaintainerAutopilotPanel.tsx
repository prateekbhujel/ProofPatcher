import React, { useEffect, useState } from 'react';
import type { GitHubRepo } from './types';

interface Flags { fix_issues: boolean; auto_ready: boolean; auto_merge: boolean; auto_close: boolean; }
interface IssueAction { repository: string; issue_number: number; action: string; detail: string | null; published: boolean; created_at: string | null; }
interface MergeAction { repository: string; pull_number: number; outcome: string; detail: string | null; created_at: string | null; }
interface Props { connected: boolean; login: string | null; repositories: GitHubRepo[]; csrf: string; }

const FLAG_LABELS: { key: keyof Flags; label: string; hint: string }[] = [
  { key: 'fix_issues', label: 'Fix demo issue', hint: 'Complete issues in the dedicated demo scope are marked fix-ready; draft PR publication still needs its server-held approval.' },
  { key: 'auto_ready', label: 'Auto-ready', hint: 'Unavailable while automatic merge is under review.' },
  { key: 'auto_merge', label: 'Auto-merge', hint: 'Requires an approved review, passing checks, resolved threads, and strict GitHub branch protection.' },
  { key: 'auto_close', label: 'Auto-close', hint: 'Unavailable while revision safeguards are being verified.' },
];

export function MaintainerAutopilotPanel({ connected, login, repositories, csrf }: Props) {
  const [settings, setSettings] = useState<Record<string, Flags> | null>(null);
  const [issueActions, setIssueActions] = useState<IssueAction[]>([]);
  const [mergeActions, setMergeActions] = useState<MergeAction[]>([]);
  const [closeActions, setCloseActions] = useState<MergeAction[]>([]);
  const [pending, setPending] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!connected || !login) { setSettings(null); return; }
    const controller = new AbortController();
    fetch('/repositories/maintainer-autopilot', { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok || typeof data.settings !== 'object' || data.settings === null) throw new Error();
        setSettings(data.settings);
        setIssueActions(Array.isArray(data.issueActions) ? data.issueActions : []);
        setMergeActions(Array.isArray(data.mergeActions) ? data.mergeActions : []);
        setCloseActions(Array.isArray(data.closeActions) ? data.closeActions : []);
      })
      .catch(() => { if (!controller.signal.aborted) setError('Could not load maintainer autopilot settings.'); });
    return () => controller.abort();
  }, [connected, login]);

  const owned = repositories.filter((repo) => !repo.isPrivate && login && repo.owner.toLowerCase() === login.toLowerCase());

  const toggle = async (repository: string, flag: keyof Flags, next: boolean) => {
    const key = `${repository}:${flag}`;
    setPending(key); setError(null);
    try {
      const response = await fetch('/repositories/maintainer-autopilot', {
        method: 'POST', credentials: 'same-origin', headers: {
          'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf,
        }, body: JSON.stringify({ repository, flag, enabled: next }),
      });
      const data = await response.json();
      if (!response.ok || data.success !== true) throw new Error(typeof data.error === 'string' ? data.error : 'Could not update setting.');
      setSettings((items) => ({
        ...(items ?? {}),
        [repository.toLowerCase()]: { fix_issues: false, auto_ready: false, auto_merge: false, auto_close: false, ...(items?.[repository.toLowerCase()] ?? {}), [flag]: next },
      }));
    } catch (err) { setError(err instanceof Error ? err.message : 'Could not update setting.'); }
    finally { setPending(null); }
  };

  return (
    <section className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-3 text-xs" aria-labelledby="maintainer-autopilot-title">
      <h2 id="maintainer-autopilot-title" className="text-sm font-semibold">Autopilot — maintainer extension</h2>
      <p>Choose actions for public repositories you own. Auto-ready and auto-close are unavailable.</p>
      {!connected ? <p>Connect GitHub to choose repositories.</p> : settings === null ? <p>Settings unavailable or loading.</p> : owned.length === 0 ? <p>No eligible public repositories.</p> : (
        <ul className="divide-y divide-slate-200 dark:divide-slate-800">
          {owned.map((repo) => {
            const flags = settings[repo.fullName.toLowerCase()] ?? { fix_issues: false, auto_ready: false, auto_merge: false, auto_close: false };
            return (
              <li key={repo.fullName} className="py-3 space-y-2">
                <div className="font-medium text-slate-900 dark:text-slate-100 break-all">{repo.fullName}</div>
                {FLAG_LABELS.map(({ key, label, hint }) => (
                  <div key={key} className="flex items-start justify-between gap-3">
                    <div>
                      <div className="text-slate-700 dark:text-slate-300">{label}</div>
                      <div className="text-slate-500 dark:text-slate-400">{hint}</div>
                    </div>
                    <label className="flex shrink-0 items-center gap-2 text-slate-700 dark:text-slate-300">
                      <span>{key === 'auto_ready' || key === 'auto_close' ? 'Unavailable' : flags[key] ? 'On' : 'Off'}</span>
                      <input type="checkbox" checked={flags[key]} disabled={pending !== null || ((key === 'auto_ready' || key === 'auto_close') && !flags[key])}
                        aria-label={`${label} for ${repo.fullName}`}
                        onChange={(event) => void toggle(repo.fullName, key, event.currentTarget.checked)} />
                    </label>
                  </div>
                ))}
              </li>
            );
          })}
        </ul>
      )}
      {(issueActions.length > 0 || mergeActions.length > 0 || closeActions.length > 0) && (
        <div className="space-y-2">
          <h3 className="font-semibold text-slate-900 dark:text-slate-100">Recent autopilot activity</h3>
          <ul className="space-y-1 text-slate-600 dark:text-slate-300">
            {issueActions.map((a, i) => (
              <li key={`i-${i}`}>{a.repository} issue #{a.issue_number}: {a.action.replace(/_/g, ' ')}{a.published ? ' · Comment posted' : ' · Recorded locally'}{a.detail ? ` — ${a.detail}` : ''}</li>
            ))}
            {mergeActions.map((a, i) => (
              <li key={`m-${i}`}>{a.repository} PR #{a.pull_number}: merge {a.outcome}{a.detail ? ` — ${a.detail}` : ''}</li>
            ))}
            {closeActions.map((a, i) => (
              <li key={`c-${i}`}>{a.repository} PR #{a.pull_number}: close {a.outcome}{a.detail ? ` — ${a.detail}` : ''}</li>
            ))}
          </ul>
        </div>
      )}
      {error && <p role="alert" className="text-rose-700 dark:text-rose-300">{error}</p>}
    </section>
  );
}
