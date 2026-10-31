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
const dockModule = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/bits/Dock.tsx'));
const sparkModule = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/bits/ClickSpark.tsx'));
const shinyModule = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/bits/ShinyText.tsx'));
const appDock = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/os/AppDock.tsx'));
const menuBar = loader.load(loader.resolveFile(HERE, '../../resources/js/Components/os/MenuBar.tsx'));

test('the dock renders labeled app buttons with pressed states and no menu until opened', () => {
  const html = renderToStaticMarkup(React.createElement(dockModule.default, {
    items: [
      { label: 'Issues', icon: 'I', onClick: () => {} },
      { separator: true },
      { label: 'Terminal', icon: 'T', active: true, onClick: () => {} },
    ],
    position: 'left',
  }));
  assert.match(html, /role="toolbar"/);
  assert.match(html, /aria-label="Dock"/);
  assert.match(html, /aria-orientation="vertical"/);
  assert.match(html, /aria-label="Issues"/);
  assert.match(html, /aria-label="Terminal"/);
  assert.match(html, /dock-dot/);
  assert.match(html, /dock-separator/);
  assert.doesNotMatch(html, /dock-menu-list/);
});

test('the app dock lists every rail app and dots the open ones', () => {
  const html = renderToStaticMarkup(React.createElement(appDock.AppDock, {
    onOpenApp: () => {},
    openApps: ['/terminal'],
    theme: 'light',
  }));
  for (const label of ['Issues &amp; Tasks', 'Pull Requests', 'Telemetry', 'Terminal', 'Settings', 'Docs']) {
    assert.match(html, new RegExp(label));
  }
  assert.match(html, /dock-dot/);
  assert.match(html, /os-dock-embed/);
});

test('click sparks wrap content without moving it', () => {
  const html = renderToStaticMarkup(React.createElement(sparkModule.ClickSpark, {
    sparkColor: '#2f6fed',
    children: React.createElement('p', {}, 'desktop content'),
  }));
  assert.match(html, /desktop content/);
  assert.match(html, /aria-hidden="true"/);
});

test('shiny text keeps its content readable with no-JS', () => {
  const html = renderToStaticMarkup(React.createElement(shinyModule.ShinyText, {
    text: 'ProofPatcher Kernel',
  }));
  assert.match(html, /shiny-text/);
  assert.match(html, /ProofPatcher Kernel/);
});

test('the menu bar brand shines without losing its home link', () => {
  const html = renderToStaticMarkup(React.createElement(menuBar.MenuBar, {
    center: 'next step',
    connected: true,
    userLogin: 'maintainer',
  }));
  assert.match(html, /aria-label="ProofPatcher Kernel home"/);
  assert.match(html, /shiny-text/);
  assert.match(html, /ProofPatcher Kernel/);
});
