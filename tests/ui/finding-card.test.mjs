import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
function loadTsx(relativePath) {
  const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(mod.exports, require, mod);
  return mod.exports;
}
// ReviewFinding is a type-only import; esbuild drops it, so no stub is needed.
const { FindingCard } = loadTsx('../../resources/js/Components/diff/FindingCard.tsx');

const base = {
  finding: { path: 'src/a.php', status: 'concern', lineHint: 12, comment: 'Null dereference when empty.' },
  headSha: 'a'.repeat(40),
  matchingDraft: null,
  isEditorOpen: false,
  lineKey: 'src/a.php:RIGHT:12',
  openEditorForLine() {},
  closeEditor() {},
};
const render = (props) => renderToStaticMarkup(React.createElement(FindingCard, { ...base, ...props }));

test('finding card shows verdict, explanation, and anchor proof', () => {
  const html = render({});
  assert.match(html, /Muse suggestion/);
  assert.match(html, />issue</);
  assert.match(html, /Null dereference when empty/);
  assert.match(html, /src\/a\.php:12/);
  assert.doesNotMatch(html, /Suggested fix/);
});

test('finding card splits subject and discussion, labels statuses', () => {
  const html = render({ finding: { ...base.finding, status: 'note', comment: 'Shorten this name. It is hard to read at a glance.' } });
  assert.match(html, />nitpick</);
  assert.match(html, /<p class="font-semibold">Shorten this name\.<\/p>/);
  assert.match(html, /hard to read at a glance/);
  const praise = render({ finding: { ...base.finding, status: 'approval', comment: 'Clean guard.' } });
  assert.match(praise, />praise</);
});

test('finding card shows the suggested fix as literal code', () => {
  const html = render({ finding: { ...base.finding, suggestion: 'if ($x === null) {\n  return;\n}\n$y = $a > $b;' } });
  assert.match(html, /Suggested fix/);
  assert.match(html, /if \(\$x === null\)/);
  // Angle brackets in code must render literally, never as markup.
  assert.match(html, /\$a &gt; \$b/);
  assert.doesNotMatch(html, /\$a > \$b/);
});
