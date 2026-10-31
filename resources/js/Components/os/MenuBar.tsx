import React from 'react';
import { Link } from '@inertiajs/react';
import { BrandMark } from '../ui/BrandMark';

interface MenuBarProps {
  /** Center hint, e.g. the next step. Hidden on small screens. */
  center: string;
  connected: boolean;
  userLogin: string | null;
  /** While status loads, the pill shows an honest placeholder. */
  loading?: boolean;
}

/** The one OS menu bar: desktop and every app page share it. */
export function MenuBar({ center, connected, userLogin, loading = false }: MenuBarProps) {
  return (
    <header className="sticky top-0 z-40 border-b border-white/10 bg-[#0b0f16]/95 backdrop-blur">
      <div className="mx-auto flex h-9 max-w-6xl items-center gap-3 px-4 text-xs sm:px-6">
        <Link
          href="/"
          aria-label="ProofPatcher Kernel home"
          className="flex shrink-0 items-center gap-2 font-semibold text-slate-100"
        >
          <BrandMark size={16} />
          <span>
            ProofPatcher <span className="font-normal text-slate-400">Kernel</span>
          </span>
        </Link>
        <span className="hidden min-w-0 flex-1 truncate text-center font-mono text-[11px] text-slate-400 md:block">
          {center}
        </span>
        <span
          className={`ml-auto shrink-0 rounded-full border px-2.5 py-0.5 text-[11px] font-medium md:ml-0 ${
            connected
              ? 'border-emerald-800 bg-emerald-950/60 text-emerald-300'
              : 'border-slate-700 bg-slate-800/60 text-slate-300'
          }`}
        >
          {loading ? '···' : connected ? `Connected${userLogin ? ` as @${userLogin}` : ''}` : 'Not connected'}
        </span>
      </div>
    </header>
  );
}
