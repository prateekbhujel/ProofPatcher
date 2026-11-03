import React, { useEffect, useState } from 'react';
import type { GitHubRepo } from './types';

interface Props { connected: boolean; login: string | null; repositories: GitHubRepo[]; csrf: string; }

export function AutomaticCommentsPanel({ connected, login, repositories, csrf }: Props) {
  const [enabled, setEnabled] = useState<string[] | null>(null);
  const [pending, setPending] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  useEffect(() => {
    if (!connected || !login) { setEnabled(null); return; }
    const controller = new AbortController();
    setEnabled(null);
    fetch('/repositories/auto-comments', { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok || !Array.isArray(data.enabled) || !data.enabled.every((x: unknown) => typeof x === 'string')) throw new Error();
        setEnabled(data.enabled);
      })
      .catch(() => { if (!controller.signal.aborted) setError('Could not load automatic comment settings.'); });
    return () => controller.abort();
  }, [connected, login]);

  const owned = repositories.filter((repo) => !repo.isPrivate && login && repo.owner.toLowerCase() === login.toLowerCase());
  const visible = owned.filter((repo) => repo.fullName.toLowerCase().includes(search.trim().toLowerCase()));
  const toggle = async (repository: string, next: boolean) => {
    setPending(repository); setError(null);
    try {
      const response = await fetch('/repositories/auto-comments', {
        method: 'POST', credentials: 'same-origin', headers: {
          'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf,
        }, body: JSON.stringify({ repository, enabled: next }),
      });
      const data = await response.json();
      if (!response.ok || data.success !== true) throw new Error(typeof data.error === 'string' ? data.error : 'Could not update setting.');
      setEnabled((items) => next ? [...(items ?? []).filter((x) => x !== repository.toLowerCase()), repository.toLowerCase()]
        : (items ?? []).filter((x) => x !== repository.toLowerCase()));
    } catch (err) { setError(err instanceof Error ? err.message : 'Could not update setting.'); }
    finally { setPending(null); }
  };

  return (
    <section className="bg-white dark:bg-[#141821] rounded-xl border border-slate-200 dark:border-slate-800 p-5 space-y-3 text-xs" aria-labelledby="auto-comments-title">
      <h2 id="auto-comments-title" className="text-sm font-semibold">Automatic GitHub comments</h2>
      <p>Opt in per public repository you own. With the GitHub App bot configured, autopilot may post one automated suggestion per reviewed revision. Suggestions can be wrong. This switch alone never merges or closes anything — those have their own switches in the maintainer extension below.</p>
      <p>Bot posting requires the app ID and a private key configured on this machine. Without them, comments are not posted.</p>
      {!connected ? <p>Connect GitHub to choose repositories.</p> : enabled === null ? <p>Settings unavailable or loading.</p> : owned.length === 0 ? <p>No eligible public repositories.</p> : (
        <div className="space-y-2">
        <input type="search" value={search} onChange={(event) => setSearch(event.currentTarget.value)}
          aria-label="Search repositories for automatic comments" placeholder="Search repositories"
          className="w-full rounded-md border border-slate-200 bg-transparent px-3 py-2 dark:border-slate-700" />
        {visible.length === 0 ? <p>No matching repositories.</p> : <ul className="max-h-56 overflow-y-auto divide-y divide-slate-200 dark:divide-slate-800">
          {visible.map((repo) => {
            const isOn = enabled.includes(repo.fullName.toLowerCase());
            return <li key={repo.fullName} className="flex justify-between gap-3 py-2">
              <span>{repo.fullName}</span><label><span>{isOn ? 'On' : 'Off'} </span>
                <input type="checkbox" checked={isOn} disabled={pending !== null} aria-label={`Automatic GitHub comments for ${repo.fullName}`}
                  onChange={(event) => void toggle(repo.fullName, event.currentTarget.checked)} /></label>
            </li>;
          })}
        </ul>}
        </div>
      )}
      {error && <p role="alert">{error}</p>}
    </section>
  );
}
