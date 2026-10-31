import React from 'react';
import { Link } from '@inertiajs/react';
import { BrandMark } from './ui/BrandMark';

interface AppLayoutProps {
  activeNav?: 'kernel' | 'overview' | 'repositories' | 'pulls' | 'checks' | 'issues' | 'settings' | 'docs' | 'telemetry';
  children: React.ReactNode;
}

function pill(active: boolean): string {
  return `px-2.5 py-1 rounded-md text-xs font-medium transition whitespace-nowrap ${
    active
      ? 'bg-[var(--accent-soft)] text-[var(--accent-text)]'
      : 'text-[var(--fg-muted)] hover:bg-[var(--bg-hover)] hover:text-[var(--fg)]'
  }`;
}

export function AppLayout({ activeNav, children }: AppLayoutProps) {
  const workspace = activeNav === 'issues';

  return (
    <div className="min-h-screen bg-[var(--bg)] text-[var(--fg)]">
      {!workspace && (
        <header className="sticky top-0 z-30 border-b border-[var(--border)] bg-[var(--bg-sidebar)]">
          <div className="mx-auto max-w-6xl h-12 px-4 sm:px-6 flex items-center justify-between gap-4">
            <Link
              href="/"
              className="inline-flex items-center gap-2 text-[13px] font-semibold whitespace-nowrap shrink-0 text-[var(--fg)] hover:text-[var(--accent-text)] transition"
              aria-label="ProofPatcher Kernel home"
            >
              <BrandMark size={20} />
              <span>
                ProofPatcher <span className="font-normal text-[var(--fg-muted)]">Kernel</span>
              </span>
            </Link>

            <nav className="flex items-center gap-0.5 shrink-0 overflow-x-auto" aria-label="Primary">
              <Link href="/issues" className={pill(false)}>
                Issues &amp; Tasks
              </Link>
              <Link
                href="/repositories"
                className={pill(activeNav === 'repositories' || activeNav === 'pulls')}
              >
                Pull Requests
              </Link>
              <Link href="/telemetry" className={pill(activeNav === 'telemetry')}>
                Telemetry
              </Link>
              <Link href="/settings" className={pill(activeNav === 'settings')}>
                Settings
              </Link>
            </nav>
          </div>
        </header>
      )}
      <main className={workspace ? 'min-h-screen w-full' : 'mx-auto max-w-6xl px-4 py-6 sm:px-6'}>
        {children}
      </main>
    </div>
  );
}
