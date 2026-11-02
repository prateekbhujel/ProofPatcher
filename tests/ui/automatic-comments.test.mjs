import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformSync } from 'esbuild';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/Components/settings/AutomaticCommentsPanel.tsx', import.meta.url), 'utf8');
const code = transformSync(source, { loader: 'tsx', format: 'cjs' }).code;
const mod = { exports: {} };
new Function('exports', 'require', 'module', code)(mod.exports, require, mod);

test('automatic comment settings disclose scope and never assume enabled before loading', () => {
  const html = renderToStaticMarkup(React.createElement(mod.exports.AutomaticCommentsPanel, {
    connected: true, login: 'maintainer', repositories: [], csrf: 'test',
  }));
  assert.match(html, /Opt in per public repository you own/);
  assert.match(html, /GitHub App bot configured/);
  assert.match(html, /Without them, comments are not posted/);
  assert.doesNotMatch(html, /as @maintainer/);
  assert.match(html, /Suggestions can be wrong/);
  assert.match(html, /will not merge or close PRs/);
  assert.match(html, /Settings unavailable or loading/);
  assert.doesNotMatch(html, /checked=""/);
});
