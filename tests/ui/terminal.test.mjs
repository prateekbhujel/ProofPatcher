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
  usePage: () => ({ props: {} }),
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
const page = loader.load(loader.resolveFile(fileURLToPath(import.meta.url), '../../resources/js/Pages/Terminal.tsx'));

test('the terminal boots read-only inside the console frame', () => {
  const html = renderToStaticMarkup(React.createElement(page.default, {}));
  assert.match(html, /aria-label="Console"/);
  assert.match(html, /ProofPatcher Kernel web terminal — read-only/);
  assert.match(html, /Type &#x27;help&#x27; to list commands/);
  assert.match(html, /aria-label="Terminal command"/);
  assert.match(html, /aria-label="Terminal transcript"/);
  assert.match(html, /proofpatcher/);
  assert.match(html, /aria-label="ProofPatcher console home"/);
});
