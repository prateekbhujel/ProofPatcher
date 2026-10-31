import React, { useState } from 'react';
import { AppWindow as AppWindowIcon, ArrowRight } from 'lucide-react';
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
} from './DesktopWindows';

export type DesktopWindowId = 'mission' | 'console' | 'findings' | 'engine';

const WINDOW_DEFS: { id: DesktopWindowId; title: string; offset: { x: number; y: number } }[] = [
  { id: 'mission', title: 'mission', offset: { x: 8, y: 0 } },
  { id: 'console', title: 'console', offset: { x: 48, y: 48 } },
  { id: 'findings', title: 'findings', offset: { x: 88, y: 96 } },
  { id: 'engine', title: 'engine', offset: { x: 128, y: 144 } },
];

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

/** An app window body: the page embedded chromeless so windows never nest. */
export function AppFrame({ href, title }: { href: string; title: string }) {
  return (
    <iframe
      src={`${href}?embed=1`}
      title={`${title} app`}
      loading="lazy"
      className="block h-full w-full border-0 bg-[var(--bg)]"
    />
  );
}

/**
 * The Kernel desktop: wallpaper, icon rail, floating windows, app windows,
 * start menu, taskbar, and the desktop's own right-click menu. SSR renders
 * every native window's content stacked (no-JS still reads everything);
 * desktop clients get the floating window manager.
 */
export function OsDesktop(props: OsDesktopProps) {
  // Mission + console greet; findings + engine are one click away in the
  // rail, taskbar, or start menu. Two windows read; four windows crowd.
  const [open, setOpen] = useState<Record<string, boolean>>({
    mission: true,
    console: true,
    findings: false,
    engine: false,
  });
  const [minimized, setMinimized] = useState<Record<string, boolean>>({});
  const [maximized, setMaximized] = useState<Record<string, boolean>>({});
  const [closing, setClosing] = useState<Record<string, boolean>>({});
  const [order, setOrder] = useState<string[]>(['console', 'findings', 'engine', 'mission']);
  const [apps, setApps] = useState<string[]>([]);
  const [startOpen, setStartOpen] = useState(false);
  const [ctx, setCtx] = useState<{ x: number; y: number } | null>(null);

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
        <MissionContent nextStep={props.nextStep} stages={props.stages} />
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
      <AppFrame href={href} title={appTitle(href)} />,
      { flush: true, wide: true },
    );
  };

  return (
    <div
      className="os-wallpaper relative"
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
          <div className="hidden border-t border-white/10 pt-2 sm:block" aria-hidden="true" />
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
                        ? 'bg-white/10 text-white'
                        : 'text-slate-400 hover:bg-white/5 hover:text-slate-200'
                    }`}
                  >
                    <span
                      className={`h-1.5 w-1.5 shrink-0 rounded-full ${active ? 'bg-emerald-400' : 'bg-slate-600'}`}
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
      />
    </div>
  );
}
