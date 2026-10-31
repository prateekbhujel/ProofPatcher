import React, { useEffect, useState } from 'react';

interface BootScreenProps {
  version?: string;
  lines: string[];
}

const SEEN_KEY = 'pp-booted';

function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function alreadyBooted(): boolean {
  try {
    return typeof sessionStorage !== 'undefined' && sessionStorage.getItem(SEEN_KEY) === '1';
  } catch {
    return false;
  }
}

function markBooted(): void {
  try {
    sessionStorage.setItem(SEEN_KEY, '1');
  } catch {
    /* storage unavailable: boot shows again next visit */
  }
}

/**
 * Full-screen kernel boot overlay. Shows once per session, reveals its lines
 * in sequence, then fades. Click anywhere to skip. Server render includes
 * every line so the markup is stable with and without JS.
 */
export function BootScreen({ version = 'v0.3', lines }: BootScreenProps) {
  // SSR (and no-JS) renders the full transcript; clients start clean so the
  // first paint never flashes full-then-empty, and repeat visits skip entirely.
  const [visible, setVisible] = useState(() => (typeof window === 'undefined' ? true : !alreadyBooted()));
  const [shown, setShown] = useState(() => (typeof window === 'undefined' ? lines.length : 0));
  const [leaving, setLeaving] = useState(false);

  useEffect(() => {
    if (alreadyBooted()) {
      setVisible(false);
      return;
    }
    markBooted();
    if (prefersReducedMotion()) {
      setShown(lines.length);
      const t = window.setTimeout(() => setVisible(false), 400);
      return () => window.clearTimeout(t);
    }
    const step = 140;
    const timers: number[] = [];
    for (let i = 1; i <= lines.length; i += 1) {
      timers.push(window.setTimeout(() => setShown(i), i * step));
    }
    const doneAt = lines.length * step + 550;
    timers.push(window.setTimeout(() => setLeaving(true), doneAt));
    timers.push(window.setTimeout(() => setVisible(false), doneAt + 300));
    return () => timers.forEach((t) => window.clearTimeout(t));
  }, [lines.length]);

  if (!visible) return null;

  return (
    <div
      role="presentation"
      aria-hidden="true"
      onClick={() => setVisible(false)}
      className={`fixed inset-0 z-50 flex items-center justify-center bg-[#0b0e14] ${leaving ? 'boot-leave' : ''}`}
    >
      <div className="term-scan pointer-events-none absolute inset-0" />
      <div className="relative w-full max-w-md px-8 font-mono text-[13px] leading-6">
        <p className="text-slate-500">ProofPatcher Kernel {version} — boot</p>
        <div className="mt-3 space-y-1 overflow-x-auto">
          {lines.slice(0, shown).map((line, i) => (
            <p key={i} className="whitespace-nowrap text-slate-300">
              {line}
            </p>
          ))}
        </div>
        <div className="mt-4 h-0.5 w-full overflow-hidden rounded bg-slate-800" aria-hidden="true">
          <div
            className="h-full bg-emerald-400 transition-[width] duration-150"
            style={{ width: `${lines.length === 0 ? 100 : (shown / lines.length) * 100}%` }}
          />
        </div>
        <p className="mt-3 text-slate-600">
          <span className="pp-caret" /> click anywhere to skip
        </p>
      </div>
    </div>
  );
}
