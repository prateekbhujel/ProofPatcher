export interface CheckPatch {
  file?: string;
  find?: string;
  replace?: string;
}

export interface CheckForm {
  action: string;
  fixture: string;
  find: string;
  replace: string;
  source_run: string;
}

export function initialCheckForm(runId: string, fixture: string, proposal: { patch?: CheckPatch } | null, reportPatch?: CheckPatch): CheckForm {
  const patch = proposal?.patch ?? reportPatch;
  return {
    action: 'verify', fixture,
    find: patch?.find ?? 'pointer-events: auto;',
    replace: patch?.replace ?? 'pointer-events: none;',
    source_run: runId,
  };
}

export function isDisplayedMuseProposal(data: CheckForm, proposal: { patch?: CheckPatch; sourceRunId?: string } | null, runId: string): boolean {
  const patch = proposal?.patch;
  return !!patch && proposal?.sourceRunId === runId && patch.file === data.fixture
    && patch.find === data.find && patch.replace === data.replace;
}

// Keep unsaved edits if the server returns an error; successful responses must
// remount so the form reflects the new run or the model's actual proposal.
export function preserveCheckState(page: { props: Record<string, unknown> }): boolean {
  return typeof page.props.error === 'string' && page.props.error.length > 0;
}
