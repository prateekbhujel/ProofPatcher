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
const { AutopilotBudgetCard } = loadTsx('../../resources/js/Components/settings/AutopilotBudgetCard.tsx');
const render = (props) => renderToStaticMarkup(React.createElement(AutopilotBudgetCard, { onSave() {}, saving: false, ...props }));

test('budget card states spend and caps honestly', () => {
  const uncapped = render({ caps: null, spentAttempts: 4, spentDollars: 0.063 });
  assert.match(uncapped, /Autopilot spending cap/);
  assert.match(uncapped, /Spent so far: 4 attempts · \$0\.06 reserved/);
  assert.match(uncapped, /No cap set/);
  assert.match(uncapped, /Save cap/);

  const capped = render({ caps: { maxAttempts: 25, maxReservedDollars: 2.5 }, spentAttempts: 4, spentDollars: 0.063 });
  assert.match(capped, /Capped at 25 attempts and \$2\.50/);
  assert.match(capped, /value="25"/);
  assert.match(capped, /value="2\.5"/);
  assert.match(capped, /never raise the local request budget/);
});

test('budget card shows a saving state', () => {
  const html = renderToStaticMarkup(React.createElement(AutopilotBudgetCard, {
    caps: null, spentAttempts: 0, spentDollars: 0, onSave() {}, saving: true,
  }));
  assert.match(html, /Saving…/);
  assert.match(html, /disabled=""/);
});
