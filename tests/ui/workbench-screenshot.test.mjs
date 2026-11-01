import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
function loadTsx(relativePath, customRequire = require) {
  const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(mod.exports, customRequire, mod);
  return mod.exports;
}
const router = { post() {} };
const { WorkbenchScreenshot } = loadTsx('../../resources/js/Components/workbench/WorkbenchScreenshot.tsx',
  (id) => (id === '@inertiajs/react' ? { router } : require(id)));
const render = (props) => renderToStaticMarkup(React.createElement(WorkbenchScreenshot, props));

test('screenshot block offers upload with a local-only promise', () => {
  const html = render({ hasScreenshot: false });
  assert.match(html, /Repro screenshot/);
  assert.match(html, /never sent to a model/);
  assert.match(html, /Attach screenshot/);
  assert.match(html, /accept="image\/png,image\/jpeg"/);
  assert.doesNotMatch(html, /<img/);
});

test('screenshot block shows the saved image without an upload form', () => {
  const html = render({ hasScreenshot: true });
  assert.match(html, /<img[^>]*src="\/issues\/screenshot"/);
  assert.match(html, /alt="Repro screenshot for this issue"/);
  assert.doesNotMatch(html, /Attach screenshot/);
});
