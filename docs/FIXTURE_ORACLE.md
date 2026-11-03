# Isolated fixture checks

On the dedicated test repository, a Muse repair proposal can be checked
before anyone opens a draft pull request. The checks run the proposal against
the included order-total fixture in isolated sandboxes, so the candidate never
shares a process with the test harness that judges it.

## How it works

1. **Propose.** From the dedicated issue, Muse proposes a change to
   `src/OrderTotal.php`. The proposal stays a suggestion until a maintainer
   acts on it.
2. **Run checks.** *Run checks* executes three isolated runs against the
   pinned fixture: the original code, a negative control, and the proposed
   change. Each run happens in its own network-blocked sandbox with no
   mounted secrets, and the coordinator compares the observed results.
3. **Review the observation.** The card reports each run in plain language —
   issue reproduced, control rejected, change matched — and states the scope:
   the included fixture cases only. This is an observation, not a verified
   run, and the interface never presents it as one.
4. **Create the draft.** After a passing observation, *Create draft PR* asks
   for confirmation, then publishes a draft against the dedicated repository.
   If an open draft already contains identical bytes, the app links to that
   draft instead of opening a duplicate. Concurrent attempts are claimed
   against the task record, so a double submit cannot create two drafts.
   In live checks, the workbench displayed a link to an existing draft.
   Duplicate detection is covered by an integration test with a simulated
   GitHub client; a fresh live draft publication has not yet been demonstrated.

## Boundaries

- Only the configured dedicated repository, its first issue, and
  `src/OrderTotal.php` are eligible. Anything else fails closed.
- A passing observation means the proposal matched the finite fixture cases.
  It covers the included cases only, does not prove the patch is generally
  correct or safe, and never replaces the repository's own tests or maintainer
  review. Arbitrary-code verification and general repository automation are
  not supported.
- Publication requires a single-use approval bound to the signed-in owner,
  the exact candidate bytes, and the recorded proposal. A stale, changed, or
  simulated result cannot publish.
- Older verification reports from the shared-oracle path are shown separately
  and do not enable this flow.

## Trying it locally

These checks use Python and Modal sandboxes. You'll need a Modal account,
the Python SDK, and authentication on the machine running ProofPatcher. For a
new local environment, you can follow [Modal's setup guide](https://modal.com/docs/guide/getting-started):

```bash
python3 -m venv ~/.local/share/proofpatch/venv
~/.local/share/proofpatch/venv/bin/python -m pip install modal
~/.local/share/proofpatch/venv/bin/python -m modal setup
```

The app uses that Python path by default. If your Modal environment is
elsewhere, set `MODAL_PYTHON_PATH` to the absolute path of its Python
executable in your private `.env` and restart the app. Configure
`PROOFPATCH_TEST_REPO` and `PROOFPATCH_TEST_BASE_COMMIT` for the dedicated
test case as described in `.env.example`. Keep credentials out of the
repository. Modal usage may consume credits or incur charges.

Without a configured Modal connection, checks cannot complete and the app
does not publish a draft. Modal is not needed to browse issues, request a
Muse review, or run the separate browser-based checkout demo.
