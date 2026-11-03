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
   In live checks on the dedicated repository, the flow confirmed this
   behavior by linking to the existing draft PR #14 rather than opening a
   duplicate.

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

The checks need a configured sandbox provider; without one, the app explains
that checks did not complete and nothing is published. No provider call is
needed to browse issues, review proposals, or read past observations.
