import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
const inertia = {
  Link: ({ children, href, ...props }) => React.createElement('a', { href, ...props }, children),
  Head: ({ title }) => React.createElement('title', {}, title),
  router: { post() {} },
};

/** Recursive TSX loader: resolves relative imports against the importing file. */
function createLoader() {
  const cache = new Map();
  function resolveFile(base, spec) {
    const url = new URL(spec, pathToFileURL(base));
    for (const candidate of [url.pathname, `${url.pathname}.tsx`, `${url.pathname}.ts`]) {
      try {
        readFileSync(candidate, 'utf8');
        return candidate;
      } catch {
        /* try next */
      }
    }
    throw new Error(`Cannot resolve ${spec} from ${base}`);
  }
  function load(absPath) {
    if (cache.has(absPath)) return cache.get(absPath).exports;
    const source = readFileSync(absPath, 'utf8');
    const loader = absPath.endsWith('.ts') ? 'ts' : 'tsx';
    const { code } = transformSync(source, { loader, format: 'cjs' });
    const mod = { exports: {} };
    cache.set(absPath, mod);
    const localRequire = (id) => {
      if (id === '@inertiajs/react') return inertia;
      if (id.startsWith('.')) return load(resolveFile(absPath, id));
      return require(id);
    };
    new Function('exports', 'require', 'module', code)(mod.exports, localRequire, mod);
    return mod.exports;
  }
  return { load, resolveFile };
}

const loader = createLoader();
const HERE = fileURLToPath(import.meta.url);
const desktop = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/os/OsDesktop.tsx'));
const ctxMenu = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/os/DesktopContextMenu.tsx'));
const win = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/os/Window.tsx'));
const menu = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/os/StartMenu.tsx'));
const banners = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/ui/AlertBanner.tsx'));

const noop = () => {};

const baseProps = {
  connected: true,
  userLogin: 'maintainer',
  reposCount: 2,
  selectedRepo: 'acme/repo',
  openIssues: 3,
  openPulls: 1,
  provider: { label: 'Baseten Inference API', model: 'meta-models/muse-spark-open-1026', keyReady: true },
  budget: { attempts: 1, maxAttempts: 25, remainingMicros: 220000, canRequest: true },
  worker: { isLive: false, status: 'offline' },
  autoStats: { totalReviews: 3, completed: 1, failed: 0, skippedPrivate: 2, skippedBudget: 0 },
  recentFindings: [],
  nextStep: { key: 'target', title: 'Open an issue or pull request', detail: 'Pick a target.', href: '/issues', cta: 'Open the workbench' },
  stages: [
    { key: 'intake', title: 'Intake', detail: 'Adapter connected.', state: 'done', href: '/issues' },
    { key: 'propose', title: 'Propose', detail: 'No proposal yet.', state: 'active', href: '/issues' },
    { key: 'verify', title: 'Verify', detail: 'Not checked yet.', state: 'todo', href: '/issues' },
    { key: 'publish', title: 'Publish', detail: 'Nothing published.', state: 'todo', href: '/issues' },
  ],
  lastVerification: null,
  publishedPr: null,
};

test('the desktop greets with mission and console; the rest is one click away', () => {
  const html = renderToStaticMarkup(React.createElement(desktop.OsDesktop, baseProps));
  for (const id of ['mission', 'console']) {
    assert.match(html, new RegExp(`data-window="${id}"`));
  }
  for (const id of ['findings', 'engine']) {
    assert.doesNotMatch(html, new RegExp(`data-window="${id}"`));
  }
  assert.match(html, /Start here: Open an issue or pull request/);
  assert.match(html, /\$ proofpatcher status/);
  assert.match(html, /ProofPatcher Kernel/);
  for (const href of ['/issues', '/repositories', '/telemetry', '/terminal', '/settings', '/docs']) {
    assert.match(html, new RegExp(`href="${href}"`));
  }
  for (const id of ['mission', 'console', 'findings', 'engine']) {
    assert.match(html, new RegExp(`Toggle ${id} window`));
  }
});

test('the desktop boots with status lines and taskbar state', () => {
  const html = renderToStaticMarkup(React.createElement(desktop.OsDesktop, baseProps));
  assert.match(html, /\[ok\] adapter    github · 2 repos/);
  assert.match(html, /autopilot offline/);
  assert.match(html, /\$0\.22 left/);
  assert.match(html, /Toggle mission window/);
  assert.match(html, /Open start menu/);
});

test('a window renders its chrome and hides when minimized', () => {
  const chrome = {
    id: 'console', title: 'console', focused: true, minimized: false, maximized: false, closing: false,
    zIndex: 10, defaultOffset: { x: 0, y: 0 }, onFocus: noop, onMinimize: noop, onClose: noop,
    onToggleMaximize: noop, children: React.createElement('p', {}, 'window body'),
  };
  const html = renderToStaticMarkup(React.createElement(win.Window, chrome));
  assert.match(html, /data-window="console"/);
  assert.match(html, /window body/);
  assert.match(html, /Close console/);
  assert.match(html, /Minimize console/);
  assert.match(html, /Maximize console/);

  const hidden = renderToStaticMarkup(React.createElement(win.Window, { ...chrome, focused: false, minimized: true }));
  assert.match(hidden, /hidden=""/);
  assert.match(hidden, /window body/);

  const maxed = renderToStaticMarkup(React.createElement(win.Window, { ...chrome, maximized: true }));
  assert.match(maxed, /data-maximized="true"/);
  assert.match(maxed, /Restore console/);
});

test('an app frame embeds its page chromeless', () => {
  const html = renderToStaticMarkup(React.createElement(desktop.AppFrame, { href: '/docs', title: 'docs' }));
  assert.match(html, /<iframe/);
  assert.match(html, /src="\/docs\?embed=1"/);
  assert.match(html, /Loading docs/);
});

test('an embedded app refreshes desktop status after Inertia navigation', () => {
  const doc = new EventTarget();
  let refreshes = 0;
  const frame = { contentDocument: doc };
  const hooks = { onOpenApp: noop, onContextMenu: noop, onStatusChanged: () => refreshes++ };
  desktop.wireFrame(frame, hooks);
  assert.equal(refreshes, 1, 'opening a frame refreshes from the server');
  doc.dispatchEvent(new Event('inertia:success'));
  assert.equal(refreshes, 2, 'a completed embedded action refreshes the desktop');
  desktop.wireFrame(frame, hooks);
  doc.dispatchEvent(new Event('inertia:success'));
  assert.equal(refreshes, 3, 'wiring again does not create duplicate listeners');
  const inaccessible = { get contentDocument() { throw new Error('cross-origin'); } };
  assert.doesNotThrow(() => desktop.wireFrame(inaccessible, hooks));
  assert.equal(refreshes, 3, 'an inaccessible frame cannot trigger a refresh');
});

test('the start menu stays closed until opened, then lists apps, windows, and next', () => {
  const closed = renderToStaticMarkup(React.createElement(menu.StartMenu, {
    open: false, apps: [], windows: [], nextTitle: 'N', nextDetail: 'D', nextHref: '/x', nextCta: 'Go',
    statusLine: 'ok', onToggleWindow: noop, onClose: noop,
  }));
  assert.equal(closed, '');

  const html = renderToStaticMarkup(React.createElement(menu.StartMenu, {
    open: true,
    apps: [{ href: '/issues', label: 'Issues & Tasks', detail: 'Workbench' }],
    windows: [{ id: 'console', title: 'console', open: true, minimized: false }],
    nextTitle: 'Connect GitHub',
    nextDetail: 'Link your account.',
    nextHref: '/issues',
    nextCta: 'Continue with GitHub',
    statusLine: '$0.22 left · autopilot offline',
    onToggleWindow: noop,
    onClose: noop,
  }));
  assert.match(html, /Start menu/);
  assert.match(html, /Issues &amp; Tasks/);
  assert.match(html, /console/);
  assert.match(html, /Next: Connect GitHub/);
  assert.match(html, /Continue with GitHub/);
  assert.match(html, /\$0\.22 left · autopilot offline/);
});

test('an alert banner names where, why, the fix, and the action', () => {
  const html = renderToStaticMarkup(React.createElement(banners.AlertBanner, {
    tone: 'warn',
    where: 'muse · provider key',
    why: 'Key missing — reviews cannot run without a provider key.',
    fix: 'Add a Baseten or Meta key in Settings → Providers.',
    action: { label: 'Open Settings', href: '/settings' },
  }));
  assert.match(html, /muse · provider key/);
  assert.match(html, /Key missing/);
  assert.match(html, /Add a Baseten or Meta key/);
  assert.match(html, /href="\/settings"[^>]*>.*Open Settings/s);
});

test('the start menu groups apps into folders and hides the windows section when empty', () => {
  const html = renderToStaticMarkup(React.createElement(menu.StartMenu, {
    open: true,
    apps: [],
    folders: [
      { title: 'pipeline', apps: [{ href: '/issues', label: 'Issues & Tasks', detail: 'Workbench' }] },
      { title: 'system', apps: [{ href: '/terminal', label: 'Terminal', detail: 'Console' }] },
    ],
    windows: [],
    nextTitle: 'Connect GitHub',
    nextDetail: 'Link your account.',
    nextHref: '/issues',
    nextCta: 'Continue with GitHub',
    statusLine: '···',
    onToggleWindow: noop,
    onClose: noop,
  }));
  assert.match(html, /pipeline/);
  assert.match(html, /system/);
  assert.match(html, /href="\/issues"/);
  assert.match(html, /href="\/terminal"/);
  assert.doesNotMatch(html, /Windows/);
});

test('the shared app catalog links every app and hides internal pages', () => {
  const apps = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/os/apps.ts'));
  const hrefs = apps.APP_FOLDERS.flatMap((folder) => folder.apps.map((app) => app.href));
  for (const href of ['/issues', '/repositories', '/telemetry', '/terminal', '/settings', '/docs']) {
    assert.ok(hrefs.includes(href), `missing ${href}`);
  }
  assert.ok(!hrefs.includes('/checks'));
  assert.ok(!hrefs.includes('/overview'));
});

test('the desktop context menu offers apps and window actions', () => {
  const html = renderToStaticMarkup(React.createElement(ctxMenu.DesktopContextMenu, {
    x: 120, y: 80, onOpenApp: noop, onOpenNotes: noop, onToggleTheme: noop, onShowAll: noop, onMinimizeAll: noop, onClose: noop,
  }));
  assert.match(html, /Desktop menu/);
  assert.match(html, /Open Terminal/);
  assert.match(html, /Open Issues &amp; Tasks/);
  assert.match(html, /Open Notes/);
  assert.match(html, /Switch to (light|dark) mode/);
  assert.match(html, /Show all windows/);
  assert.match(html, /Minimize all windows/);
  assert.match(html, /Close context menu/);
});

test('an empty state pairs its title with a hint and an action', () => {
  const html = renderToStaticMarkup(React.createElement(banners.EmptyState, {
    title: 'No automatic reviews yet',
    hint: 'Connect GitHub to enable automatic reviews.',
    action: { label: 'Continue with GitHub', href: '/issues' },
  }));
  assert.match(html, /No automatic reviews yet/);
  assert.match(html, /Connect GitHub to enable automatic reviews/);
  assert.match(html, /href="\/issues"[^>]*>.*Continue with GitHub/s);
});
