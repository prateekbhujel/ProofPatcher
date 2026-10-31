import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
function loadTs(relativePath) {
  const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'ts', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(mod.exports, require, mod);
  return mod.exports;
}
const { copyText } = loadTs('../../resources/js/Components/ui/copyText.ts');

const realNavigator = globalThis.navigator;
const realDocument = globalThis.document;

test('copyText resolves true when the clipboard API succeeds', async () => {
  Object.defineProperty(globalThis, 'navigator', {
    value: { clipboard: { writeText: async () => {} } },
    configurable: true,
  });
  try {
    assert.equal(await copyText('hello'), true);
  } finally {
    Object.defineProperty(globalThis, 'navigator', { value: realNavigator, configurable: true });
  }
});

test('copyText resolves false without any clipboard or DOM', async () => {
  Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true });
  try {
    assert.equal(await copyText('hello'), false);
  } finally {
    Object.defineProperty(globalThis, 'navigator', { value: realNavigator, configurable: true });
  }
});

test('copyText falls back to execCommand when the API throws', async () => {
  let removed = false;
  const area = { value: '', style: {}, select() {} };
  Object.defineProperty(globalThis, 'navigator', {
    value: { clipboard: { writeText: async () => { throw new Error('denied'); } } },
    configurable: true,
  });
  Object.defineProperty(globalThis, 'document', {
    value: {
      createElement: () => area,
      body: { appendChild() {}, removeChild() { removed = true; } },
      execCommand: () => true,
    },
    configurable: true,
  });
  try {
    assert.equal(await copyText('hello'), true);
    assert.equal(removed, true);
  } finally {
    Object.defineProperty(globalThis, 'navigator', { value: realNavigator, configurable: true });
    Object.defineProperty(globalThis, 'document', { value: realDocument, configurable: true });
  }
});
