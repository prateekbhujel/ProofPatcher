import React, { useEffect, useState } from 'react';
import { AppWindow as AppWindowIcon, ArrowRight } from 'lucide-react';
import { applyThemePreference } from '../ThemeToggle';
import { APP_FOLDERS, RAIL_APPS } from './apps';
import { BootScreen } from './BootScreen';
import { DesktopContextMenu } from './DesktopContextMenu';
import { DesktopIcons } from './DesktopIcons';
import { KernelTaskbar } from './KernelTaskbar';
import { TerminalLine } from './KernelTerminal';
import { StartMenu } from './StartMenu';
import { Window } from './Window';
import {
  ConsoleContent,
  EngineContent,
  FindingsContent,
  KernelFinding,
  KernelNextStep,
  KernelStage,
  KernelVerification,
  MissionContent,
  NotesContent,
} from './DesktopWindows';

export type DesktopWindowId = 'mission' | 'console' | 'findings' | 'engine' | 'notes';

const WINDOW_DEFS: { id: DesktopWindowId; title: string; offset: { x: number; y: number } }[] = [
  { id: 'mission', title: 'mission', offset: { x: 8, y: 0 } },
  { id: 'console', title: 'console', offset: { x: 48, y: 48 } },
  { id: 'findings', title: 'findings', offset: { x: 88, y: 96 } },
  { id: 'engine', title: 'engine', offset: { x: 128, y: 144 } },
  { id: 'notes', title: 'notes', offset: { x: 168, y: 192 } },
];

const DESKTOP_KEY = 'proofpatch.desktop.v1';

interface DesktopSnapshot {
  open?: Record<string, boolean>;
  minimized?: Record<string, boolean>;
  maximized?: Record<string, boolean>;
  order?: string[];
  apps?: string[];
}

function readSnapshot(): DesktopSnapshot {
  if (typeof window === 'undefined') return {};
  try {
    const raw = window.localStorage.getItem(DESKTOP_KEY);
    if (!raw) return {};
    const parsed = JSON.parse(raw) as DesktopSnapshot;
    if (!parsed || typeof parsed !== 'object') return {};
    const clean: DesktopSnapshot = {};
    for (const key of ['open', 'minimized', 'maximized'] as const) {
      const record = parsed[key];
      if (record && typeof record === 'object') {
        clean[key] = Object.fromEntries(
          Object.entries(record).filter(([k, v]) => typeof k === 'string' && typeof v === 'boolean'),
        );
      }
    }
    if (Array.isArray(parsed.order)) {
      clean.order = parsed.order.filter((id): id is string => typeof id === 'string').slice(0, 24);
    }
    if (Array.isArray(parsed.apps)) {
      clean.apps = parsed.apps
        .filter((href): href is string => typeof href === 'string' && /^\/[A-Za-z0-9/_.\-?=&%]*$/.test(href))
        .slice(0, 12);
    }
    return clean;
  } catch {
    return {};
  }
}

const NATIVE_IDS = WINDOW_DEFS.map((w) => w.id);

function appId(href: string): string {
  return `app:${href}`;
}

function appTitle(href: string): string {
  const found = RAIL_APPS.find((app) => app.href === href);
  return (found ? found.label : href).toLowerCase();
}

export interface OsDesktopProps {
  connected: boolean;
  userLogin: string | null;
  reposCount: number;
  selectedRepo: string | null;
  openIssues: number;
  openPulls: number;
  provider: { label: string; model: string; keyReady: boolean };
  budget: { attempts: number; maxAttempts: number; remainingMicros: number; canRequest: boolean } | null;
  autopilotCaps?: { maxAttempts: number | null; maxReservedDollars: number | null } | null;
  worker: { isLive: boolean; status: string };
  autoStats: { totalReviews: number; completed: number; failed: number; skippedPrivate: number; skippedBudget: number };
  recentFindings: KernelFinding[];
  nextStep: KernelNextStep;
  stages: KernelStage[];
  lastVerification: KernelVerification | null;
  publishedPr: { prNumber: number | null; prUrl: string | null; repository: string | null } | null;
}

export function bootLinesFor(props: {
  connected: boolean;
  reposCount: number;
  provider: { model: string };
  worker: { isLive: boolean; status: string };
}): string[] {
  return [
    `[ok] adapter    ${props.connected ? `github · ${props.reposCount} ${props.reposCount === 1 ? 'repo' : 'repos'}` : 'github · not connected'}`,
    `[ok] muse       ${props.provider.model}`,
    '[ok] verifier   sandbox receipts armed',
    props.worker.isLive ? `[ok] autopilot  ${props.worker.status}` : '[--] autopilot  offline — start with review:poll --daemon',
  ];
}

function terminalLinesFor(props: OsDesktopProps): TerminalLine[] {
  return [
    { text: '$ proofpatcher status', tone: 'muted' },
    props.connected
      ? { text: `✓ adapter   github · ${props.reposCount} ${props.reposCount === 1 ? 'repo' : 'repos'}${props.selectedRepo ? ` · ${props.selectedRepo}` : ''}`, tone: 'ok' }
      : { text: '○ adapter   github · not connected', tone: 'warn' },
    props.provider.keyReady
      ? { text: `✓ muse      ${props.provider.label} · ${props.provider.model} · key ready`, tone: 'ok' }
      : { text: `! muse      ${props.provider.label} · ${props.provider.model} · key missing`, tone: 'warn' },
    props.budget
      ? { text: `✓ budget    ${props.budget.attempts} of ${props.budget.maxAttempts} attempts · $${(props.budget.remainingMicros / 1000000).toFixed(2)} left`, tone: 'ok' }
      : { text: '○ budget    unavailable', tone: 'muted' },
    props.worker.isLive
      ? { text: `● autopilot ${props.worker.status} — ${props.autoStats.completed} reviewed · ${props.autoStats.failed} failed`, tone: 'info' }
      : { text: '○ autopilot offline — php artisan review:poll --daemon', tone: 'muted' },
    { text: `→ next      ${props.nextStep.title} — ${props.nextStep.detail}`, tone: 'cmd' },
  ];
}

export interface AppFrameHooks {
  onOpenApp: (href: string) => void;
  onContextMenu: (x: number, y: number) => void;
}

/** An app window body: the page embedded chromeless so windows never nest. */
export function AppFrame({ href, title, hooks }: { href: string; title: string; hooks?: AppFrameHooks }) {
  const src = href.includes('?') ? `${href}&embed=1` : `${href}?embed=1`;
  return (
    <iframe
      src={src}
      title={`${title} app`}
      loading="lazy"
      onLoad={hooks ? (event) => wireFrame(event.currentTarget, hooks) : undefined}
      className="block h-full w-full border-0 bg-[var(--bg)]"
    />
  );
}

/**
 * Wire a same-origin app frame into the desktop: internal link clicks open
 * app windows instead of navigating inside the frame, and right-clicks
 * surface the desktop menu. Cross-origin frames keep native behavior.
 */
function wireFrame(
  frame: HTMLIFrameElement | null,
  hooks: { onOpenApp: (href: string) => void; onContextMenu: (x: number, y: number) => void },
) {
  if (!frame) return;
  let source: Document | null = null;
  try {
    source = frame.contentDocument;
  } catch {
    return;
  }
  if (!source || (source as Document & { __proofpatchWired?: boolean }).__proofpatchWired) return;
  try {
    (source as Document & { __proofpatchWired?: boolean }).__proofpatchWired = true;
    source.addEventListener('click', (event) => {
      const anchor = (event.target as HTMLElement).closest?.('a[href]') as HTMLAnchorElement | null;
      if (!anchor) return;
      const href = anchor.getAttribute('href') ?? '';
      if (anchor.target === '_blank' || !href.startsWith('/') || href.startsWith('//')) return;
      event.preventDefault();
      hooks.onOpenApp(href);
    });
    source.addEventListener('contextmenu', (event) => {
      const target = event.target as HTMLElement;
      if (target.closest?.('input, textarea, select, [contenteditable="true"], a[href]')) return;
      event.preventDefault();
      const rect = frame.getBoundingClientRect();
      hooks.onContextMenu(rect.left + event.clientX, rect.top + event.clientY);
    });
  } catch {
    // Cross-origin frame: leave native behavior alone.
  }
}

/**
 * The Kernel desktop: wallpaper, icon rail, floating windows, app windows,
 * start menu, taskbar, and the desktop's own right-click menu. SSR renders
 * every native window's content stacked (no-JS still reads everything);
 * desktop clients get the floating window manager.
 */
export function OsDesktop(props: OsDesktopProps) {
  // Mission + console greet; findings + engine + notes are one click away in
  // the rail, taskbar, start menu, or right-click menu. Two windows read.
  const [snapshot] = useState<DesktopSnapshot>(readSnapshot);
  const [open, setOpen] = useState<Record<string, boolean>>(() => ({
    mission: true,
    console: true,
    findings: false,
    engine: false,
    notes: false,
    ...snapshot.open,
  }));
  const [minimized, setMinimized] = useState<Record<string, boolean>>(() => ({ ...snapshot.minimized }));
  const [maximized, setMaximized] = useState<Record<string, boolean>>(() => ({ ...snapshot.maximized }));
  const [closing, setClosing] = useState<Record<string, boolean>>({});
  const [order, setOrder] = useState<string[]>(() =>
    snapshot.order && snapshot.order.length > 0 ? snapshot.order : ['console', 'findings', 'engine', 'mission'],
  );
  const [apps, setApps] = useState<string[]>(() => snapshot.apps ?? []);
  const [startOpen, setStartOpen] = useState(false);
  const [ctx, setCtx] = useState<{ x: number; y: number } | null>(null);
  const [themeNonce, setThemeNonce] = useState(0);

  useEffect(() => {
    try {
      window.localStorage.setItem(DESKTOP_KEY, JSON.stringify({ open, minimized, maximized, order, apps }));
    } catch {
      // Private mode: the desktop still works, it just won't remember.
    }
  }, [open, minimized, maximized, order, apps]);

  const focus = (id: string) => setOrder((prev) => [...prev.filter((w) => w !== id), id]);
  const close = (id: string) => {
    setOpen((prev) => ({ ...prev, [id]: false }));
    setMinimized((prev) => ({ ...prev, [id]: false }));
    setMaximized((prev) => ({ ...prev, [id]: false }));
    if (id.startsWith('app:')) setApps((prev) => prev.filter((href) => appId(href) !== id));
  };
  const minimize = (id: string) => {
    setClosing((prev) => ({ ...prev, [id]: true }));
    window.setTimeout(() => {
      setMinimized((prev) => ({ ...prev, [id]: true }));
      setClosing((prev) => ({ ...prev, [id]: false }));
    }, 150);
  };
  const toggleMaximize = (id: string) => {
    setMaximized((prev) => ({ ...prev, [id]: !prev[id] }));
    focus(id);
  };
  const toggle = (id: string) => {
    const front = order[order.length - 1] === id;
    if (!open[id] || minimized[id]) {
      setOpen((prev) => ({ ...prev, [id]: true }));
      setMinimized((prev) => ({ ...prev, [id]: false }));
      focus(id);
      return;
    }
    if (front) {
      minimize(id);
      return;
    }
    focus(id);
  };
  const openApp = (href: string) => {
    const id = appId(href);
    setApps((prev) => (prev.includes(href) ? prev : [...prev, href]));
    setOpen((prev) => ({ ...prev, [id]: true }));
    setMinimized((prev) => ({ ...prev, [id]: false }));
    focus(id);
    setStartOpen(false);
    setCtx(null);
  };
  const showAll = () => {
    setMinimized({});
    setClosing({});
  };
  const minimizeAll = () => {
    const ids = [...NATIVE_IDS.filter((id) => open[id] && !minimized[id]), ...apps.filter((href) => open[appId(href)] && !minimized[appId(href)]).map(appId)];
    ids.forEach((id) => minimize(id));
  };
  const toggleTheme = () => {
    const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
    try {
      window.localStorage.setItem('proofpatch-theme', next);
    } catch {
      // Private mode: theme still flips for this session.
    }
    applyThemePreference(next);
    // Reload open app frames so embedded pages pick up the new theme.
    setThemeNonce((n) => n + 1);
  };
  const openNotes = () => {
    setOpen((prev) => ({ ...prev, notes: true }));
    setMinimized((prev) => ({ ...prev, notes: false }));
    focus('notes');
    setStartOpen(false);
    setCtx(null);
  };

  const budgetText = props.budget
    ? `$${(props.budget.remainingMicros / 1000000).toFixed(2)} left`
    : 'budget n/a';
  const statusLine = `${budgetText} · ${props.worker.isLive ? `autopilot ${props.worker.status}` : 'autopilot offline'} · ${
    props.connected ? `@${props.userLogin ?? '…'}` : 'not connected'
  }`;

  const frontId = order[order.length - 1];
  const taskbarWindows = [
    ...WINDOW_DEFS.filter((w) => open[w.id]).map((w) => ({
      id: w.id,
      title: w.title,
      minimized: minimized[w.id] === true,
      front: frontId === w.id && minimized[w.id] !== true,
    })),
    ...apps.filter((href) => open[appId(href)]).map((href) => ({
      id: appId(href),
      title: appTitle(href),
      minimized: minimized[appId(href)] === true,
      front: frontId === appId(href) && minimized[appId(href)] !== true,
    })),
  ];

  const windowChrome = (
    id: string,
    title: string,
    offset: { x: number; y: number },
    content: React.ReactNode,
    opts: { flush?: boolean; wide?: boolean } = {},
  ) => (
    <Window
      key={id}
      id={id}
      title={title}
      focused={frontId === id}
      minimized={minimized[id] === true}
      maximized={maximized[id] === true}
      closing={closing[id] === true}
      zIndex={10 + order.indexOf(id)}
      defaultOffset={offset}
      flush={opts.flush}
      wide={opts.wide}
      onFocus={focus}
      onMinimize={minimize}
      onClose={close}
      onToggleMaximize={toggleMaximize}
    >
      {content}
    </Window>
  );

  const renderNative = (id: DesktopWindowId) => {
    if (!open[id]) return null;
    const def = WINDOW_DEFS.find((w) => w.id === id);
    if (!def) return null;
    const content =
      id === 'mission' ? (
        <MissionContent nextStep={props.nextStep} stages={props.stages} onOpenApp={openApp} />
      ) : id === 'notes' ? (
        <NotesContent onSendToIssues={() => openApp('/issues')} />
      ) : id === 'console' ? (
        <div className="space-y-2">
          <ConsoleContent lines={terminalLinesFor(props)} />
          <button
            type="button"
            onClick={() => openApp('/terminal')}
            className="inline-flex items-center gap-1 text-xs font-semibold text-accent-700 dark:text-accent-300 hover:underline"
          >
            Open terminal to type commands
            <ArrowRight className="h-3 w-3" />
          </button>
        </div>
      ) : id === 'findings' ? (
        <FindingsContent
          findings={props.recentFindings}
          connected={props.connected}
          lastVerification={props.lastVerification}
          publishedPr={props.publishedPr}
        />
      ) : (
        <EngineContent
          connected={props.connected}
          reposCount={props.reposCount}
          selectedRepo={props.selectedRepo}
          openIssues={props.openIssues}
          openPulls={props.openPulls}
          provider={props.provider}
          budget={props.budget}
          autopilotCaps={props.autopilotCaps}
          worker={props.worker}
          autoStats={props.autoStats}
        />
      );
    return windowChrome(id, def.title, def.offset, content, { wide: id === 'mission' || id === 'engine' });
  };

  const renderApp = (href: string, index: number) => {
    const id = appId(href);
    if (!open[id]) return null;
    return windowChrome(
      id,
      appTitle(href),
      { x: 120 + (index % 4) * 44, y: 56 + (index % 4) * 44 },
      <AppFrame
        key={`${href}:${themeNonce}`}
        href={href}
        title={appTitle(href)}
        hooks={{ onOpenApp: openApp, onContextMenu: (x, y) => setCtx({ x, y }) }}
      />,
      { flush: true, wide: true },
    );
  };

  return (
    <div
      className="os-wallpaper relative min-h-[100dvh]"
      onContextMenu={(event) => {
        const target = event.target as HTMLElement;
        if (target.closest('input, textarea, select, [contenteditable="true"], a[href]')) return;
        event.preventDefault();
        setCtx({ x: event.clientX, y: event.clientY });
      }}
    >
      <div className="os-grid-overlay pointer-events-none absolute inset-0" aria-hidden="true" />
      <BootScreen lines={bootLinesFor(props)} />
      <div className="relative flex flex-col gap-3 p-3 pb-16 sm:flex-row sm:p-4 sm:pb-16">
        <aside aria-label="Desktop rail" className="flex shrink-0 gap-2 overflow-x-auto sm:flex-col sm:overflow-visible">
          <DesktopIcons layout="rail" onOpenApp={openApp} />
          <div className="hidden border-t border-[var(--border)] pt-2 sm:block" aria-hidden="true" />
          <ul className="flex gap-1 sm:flex-col" aria-label="Window toggles">
            {WINDOW_DEFS.map((w) => {
              const active = open[w.id] && !minimized[w.id];
              return (
                <li key={w.id}>
                  <button
                    type="button"
                    onClick={() => toggle(w.id)}
                    aria-pressed={active}
                    aria-label={`Toggle ${w.title} window`}
                    className={`flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-medium transition ${
                      active
                        ? 'bg-[var(--bg-active)] text-[var(--fg)]'
                        : 'text-[var(--fg-muted)] hover:bg-[var(--bg-hover)] hover:text-[var(--fg)]'
                    }`}
                  >
                    <span
                      className={`h-1.5 w-1.5 shrink-0 rounded-full ${active ? 'bg-emerald-500 dark:bg-emerald-400' : 'bg-slate-400'}`}
                      aria-hidden="true"
                    />
                    <AppWindowIcon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span className="hidden font-mono uppercase tracking-wider sm:inline">{w.title}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        </aside>
        <div className="relative min-h-[50vh] flex-1 space-y-3 sm:space-y-0">
          {(Object.keys(open) as string[]).filter((id) => NATIVE_IDS.includes(id as DesktopWindowId)).map((id) => renderNative(id as DesktopWindowId))}
          {apps.map((href, i) => renderApp(href, i))}
        </div>
      </div>
      <StartMenu
        open={startOpen}
        apps={[]}
        folders={APP_FOLDERS}
        windows={WINDOW_DEFS.map((w) => ({ id: w.id, title: w.title, open: open[w.id] === true, minimized: minimized[w.id] === true }))}
        nextTitle={props.nextStep.title}
        nextDetail={props.nextStep.detail}
        nextHref={props.nextStep.href}
        nextCta={props.nextStep.cta}
        statusLine={statusLine}
        onToggleWindow={toggle}
        onClose={() => setStartOpen(false)}
        onOpenApp={openApp}
      />
      {ctx && (
        <DesktopContextMenu
          x={ctx.x}
          y={ctx.y}
          onOpenApp={openApp}
          onOpenNotes={openNotes}
          onToggleTheme={toggleTheme}
          onShowAll={showAll}
          onMinimizeAll={minimizeAll}
          onClose={() => setCtx(null)}
        />
      )}
      <KernelTaskbar
        connected={props.connected}
        userLogin={props.userLogin}
        reposCount={props.reposCount}
        openIssues={props.openIssues}
        openPulls={props.openPulls}
        findingsCount={props.recentFindings.length}
        budgetText={budgetText}
        workerLive={props.worker.isLive}
        workerStatus={props.worker.status}
        startOpen={startOpen}
        onStart={() => setStartOpen((v) => !v)}
        windows={taskbarWindows}
        onToggleWindow={toggle}
        onOpenApp={openApp}
      />
    </div>
  );
}
