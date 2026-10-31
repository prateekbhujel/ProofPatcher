import React, { useEffect, useState } from 'react';

export interface TerminalLine {
  text: string;
  tone?: 'ok' | 'warn' | 'bad' | 'info' | 'muted' | 'cmd';
}

interface KernelTerminalProps {
  title?: string;
  lines: TerminalLine[];
  /** Stagger line reveals on mount. Disabled automatically for reduced motion. */
  animate?: boolean;
}

const toneClass: Record<NonNullable<TerminalLine['tone']>, string> = {
  ok: 'text-emerald-400',
  warn: 'text-amber-400',
  bad: 'text-rose-400',
  info: 'text-sky-300',
  muted: 'text-slate-500',
  cmd: 'text-slate-100',
};

function reducedMotion(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return true;
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Muse-CLI-style console window. Always shows the full transcript in markup
 * (SSR-safe); on capable clients the lines stream in one by one.
 */
export function KernelTerminal({ title = 'kernel — console', lines, animate = true }: KernelTerminalProps) {
  const [shown, setShown] = useState(() => {
    if (typeof window === 'undefined') return lines.length;
    return animate && !reducedMotion() ? 0 : lines.length;
  });

  useEffect(() => {
    if (!animate || reducedMotion()) {
      setShown(lines.length);
      return;
    }
    const timers: number[] = lines.map((_, i) => window.setTimeout(() => setShown(i + 1), 120 + i * 110));
    return () => timers.forEach((t) => window.clearTimeout(t));
  }, [lines.length, animate]);

  return (
    <div className="overflow-hidden rounded-xl border border-slate-800 bg-[#0d1117] shadow-xs">
      <div className="flex items-center gap-2 border-b border-slate-800 bg-[#010409]/60 px-4 py-2.5">
        <span className="h-2.5 w-2.5 rounded-full bg-rose-500/80" />
        <span className="h-2.5 w-2.5 rounded-full bg-amber-400/80" />
        <span className="h-2.5 w-2.5 rounded-full bg-emerald-500/80" />
        <span className="ml-2 font-mono text-[11px] text-slate-500">{title}</span>
        <span className="ml-auto flex items-center gap-1.5 font-mono text-[11px] text-emerald-400">
          <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
          live
        </span>
      </div>
      <div className="term-scan relative overflow-x-auto px-4 py-3 font-mono text-[12.5px] leading-6">
        {lines.slice(0, shown).map((line, i) => (
          <p key={i} className={`whitespace-nowrap ${toneClass[line.tone ?? 'cmd']}`}>
            {line.text}
          </p>
        ))}
        <p className="text-slate-100">
          <span className="text-emerald-400">proofpatcher</span>
          <span className="text-slate-500"> › </span>
          <span className="pp-caret" aria-hidden="true" />
        </p>
      </div>
    </div>
  );
}
