import test from 'node:test';
import assert from 'node:assert/strict';
import { loadTsModule } from './helpers/loadTsModule.mjs';

const copy = loadTsModule(new URL('../../resources/js/Components/ui/budgetCopy.ts', import.meta.url));

test('budget lines use plain words, never raw internals', () => {
  const status = copy.budgetStatusLine({ attempts: 21, maxAttempts: 25, reservedMicros: 130000, remainingMicros: 220000, canRequest: true });
  assert.equal(status, '21 model requests · $0.13 tracked');
  assert.equal(copy.budgetShortLine({ attempts: 21, maxAttempts: 25, reservedMicros: 130000, remainingMicros: 220000, canRequest: true }), '21 requests');
  assert.equal(copy.autopilotSpendLine(4, 0.063), 'Spent so far: 4 model requests · $0.06 reserved.');
  assert.equal(copy.autopilotCapLine(25, 2.5), 'Capped at 25 model requests and $2.50.');
  assert.equal(copy.autopilotCapLine(null, null), 'Uncapped.');
  assert.equal(copy.autopilotCapLine(null, 2.5), 'Capped at $2.50.');
  assert.equal(copy.reviewUsageLine(0, 10), '0 of 10 model requests');
  assert.equal(copy.reviewUsageLine(0, undefined), '0 model requests used');
  for (const line of [status, copy.autopilotCapLine(25, 2.5)]) {
    assert.doesNotMatch(line, /attempts|estimate/i);
  }
  assert.equal(typeof copy.sharedBudgetNote, 'string');
  assert.match(copy.sharedBudgetNote, /shared/i);
  assert.doesNotMatch(copy.sharedBudgetNote, /ledger|micros/i);
});
