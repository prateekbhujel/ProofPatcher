import test from 'node:test';
import assert from 'node:assert/strict';
import { loadTsModule } from './helpers/loadTsModule.mjs';

const { formatDate, formatDateTime } = loadTsModule(new URL('../../resources/js/Components/ui/date.ts', import.meta.url));

test('dates are readable and timestamps keep their UTC meaning', () => {
  assert.equal(formatDate('2026-10-10'), '10 Oct 2026');
  assert.equal(formatDateTime('2026-10-10T23:30:00-04:00'), '11 Oct 2026, 03:30 UTC');
  assert.equal(formatDateTime('2026-10-10 13:45:00'), '10 Oct 2026, 13:45 UTC');
});

test('missing and malformed dates do not crash the UI', () => {
  assert.equal(formatDateTime(null), '—');
  assert.equal(formatDate('not a date'), 'not a date');
});
