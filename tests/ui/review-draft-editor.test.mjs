import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
function load(path, imports) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8');
  const { code } = transformSync(source, { loader: 'tsx', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(mod.exports, (id) => imports[id] ?? require(id), mod);
  return mod.exports;
}
function find(tree, predicate) {
  if (!tree || typeof tree !== 'object') return null;
  if (predicate(tree)) return tree;
  for (const child of React.Children.toArray(tree.props?.children)) {
    const result = find(child, predicate);
    if (result) return result;
  }
  return null;
}

test('a draft editor stays open until the save confirms persistence', () => {
  const changes = [];
  const UnifiedTable = () => null;
  const { DiffViewer } = load('../../resources/js/Components/DiffViewer.tsx', {
    react: { ...React, useState: (initial) => [initial, (value) => changes.push(value)] },
    './diff/diffParser': { parseDiffLines: () => [], parseSplitDiffRows: () => [] },
    './ui/copyText': {},
    './diff/UnifiedDiffTable': { UnifiedDiffTable: UnifiedTable },
    './diff/SplitDiffTable': {},
  });
  let confirmSaved;
  const tree = DiffViewer({
    file: { name: 'a.php', patch: '+new', status: 'available' }, headSha: 'a'.repeat(40),
    onSaveDraft: (path, line, side, text, onSaved) => {
      assert.deepEqual([path, line, side, text], ['a.php', 4, 'RIGHT', 'Keep this comment.']);
      confirmSaved = onSaved;
    },
  });
  const table = find(tree, (node) => node.type === UnifiedTable);
  table.props.onSaveDraft('a.php', 4, 'RIGHT', 'Keep this comment.');
  assert.deepEqual(changes, [], 'pending or failed save must not close the editor');
  confirmSaved();
  assert.deepEqual(changes, [null], 'confirmed save closes the editor');
});

test('inserting a review template preserves the existing comment', () => {
  let text = 'Keep this original advice.';
  const { InlineCommentEditor } = load('../../resources/js/Components/diff/InlineCommentEditor.tsx', {
    react: { ...React, useState: (initial) => [initial, (update) => {
      if (typeof update === 'function') text = update(text);
    }] },
  });
  const tree = InlineCommentEditor({ fileName: 'a.php', lineNum: 4, side: 'RIGHT', initialText: text });
  const button = find(tree, (node) => node.type === 'button' && node.props.title === 'Insert a structured review comment template');
  button.props.onClick();
  assert.ok(text.startsWith('Keep this original advice.\n\n'));
  assert.match(text, /Review Note \(a\.php:4\)/);
});
