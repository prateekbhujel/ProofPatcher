import { useEffect, useState } from 'react';

export interface OsStatus {
  connected: boolean;
  userLogin: string | null;
  reposCount: number;
  selectedRepo: string | null;
  openIssues: number;
  openPulls: number;
  findingsCount: number;
  budgetText: string | null;
  workerLive: boolean;
  workerStatus: string;
  next: { title: string; detail: string; href: string; cta: string };
}

/** Live OS status for app pages. SSR-safe: null until the client loads it. */
export function useOsStatus(): OsStatus | null {
  const [status, setStatus] = useState<OsStatus | null>(null);

  useEffect(() => {
    let live = true;
    fetch('/os/status', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (live && data && typeof data === 'object') setStatus(data as OsStatus);
      })
      .catch(() => {
        /* shell keeps its honest placeholders */
      });
    return () => {
      live = false;
    };
  }, []);

  return status;
}
