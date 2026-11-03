import test from 'node:test';
import assert from 'node:assert/strict';
import { loadTsModule } from './helpers/loadTsModule.mjs';

const { initialCheckForm, isDisplayedMuseProposal, preserveCheckState } = loadTsModule(new URL('../../resources/js/Components/checks/patchForm.ts', import.meta.url));

test('new run and model proposal hydrate the exact selected patch', () => {
  const proposal = { sourceRunId: 'run-a', patch: { file: 'fixture.html', find: 'old css', replace: 'new css' } };
  const form = initialCheckForm('run-a', 'fixture.html', proposal);
  assert.equal(form.source_run, 'run-a');
  assert.equal(form.find, 'old css');
  assert.equal(form.replace, 'new css');
  assert.equal(isDisplayedMuseProposal(form, proposal, 'run-a'), true);
  for (const changed of [{ ...form, find: 'changed' }, { ...form, replace: 'changed' }, { ...form, fixture: 'other.html' }]) {
    assert.equal(isDisplayedMuseProposal(changed, proposal, 'run-a'), false);
  }
  assert.equal(isDisplayedMuseProposal(form, proposal, 'run-b'), false);
  assert.equal(isDisplayedMuseProposal(form, null, 'run-a'), false);
});

test('a failed request keeps unsaved form state and a successful one reloads the result', () => {
  assert.equal(preserveCheckState({ props: { error: 'Verification failed' } }), true);
  assert.equal(preserveCheckState({ props: { error: null } }), false);
  assert.equal(preserveCheckState({ props: {} }), false);
});

test('a returned report and empty replacement are preserved without a model claim', () => {
  const form = initialCheckForm('run-b', 'fixture.html', null, { file: 'fixture.html', find: 'remove me', replace: '' });
  assert.equal(form.find, 'remove me');
  assert.equal(form.replace, '');
  assert.equal(form.source_run, 'run-b');
  assert.equal(isDisplayedMuseProposal(form, null, 'run-b'), false);
});
