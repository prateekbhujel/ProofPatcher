# Maintainer autopilot

ProofPatcher's maintainer autopilot extends the existing review autopilot.
It is a set of independent, per-repository opt-ins. Everything is off by
default, public repositories only, and every action fails closed.

## Switches

All switches live in **Settings** and apply only to public repositories the
signed-in user owns and has installed:

| Switch | Where | Effect |
| --- | --- | --- |
| Autopilot reviews | Settings → Autopilot reviews | Muse reviews new/updated open PR revisions while a local worker runs |
| Automatic comments | Settings → Automatic GitHub comments | The GitHub App bot may post suggestions; requires bot credentials |
| Fix demo issue | Settings → Maintainer autopilot | Complete issues in the dedicated demo scope are marked fix-ready |
| Auto-ready | Settings → Maintainer autopilot | A gated draft PR is marked ready for review before merging |
| Auto-merge | Settings → Maintainer autopilot | Merges a PR only when the merge gate passes |
| Spend caps | Settings → Autopilot budget | Optional request/spend limits for all autopilot model calls |

Manual mode uses the same flows: a maintainer click with its existing
confirmation replaces the autopilot switches, nothing else changes.

## Issue flow

While `kernel:autopilot` or `review:poll` runs, repositories with autopilot
reviews on **and** at least one maintainer switch or automatic comments on
are also watched for open issues. For each issue revision (title, body, and
update time), exactly one action is recorded:

1. **Already fixed / has open PR** — an open or merged PR referencing the
   issue (`#n` in title or body) is reported; no new work starts.
2. **Duplicate** — the title closely matches another open issue.
3. **Needs info** — the deterministic completeness check finds missing
   reproduction steps, expected-vs-actual behavior, environment, or
   evidence, and the missing questions are listed.
4. **Fix eligible** — the issue is complete, the repository is the
   configured dedicated demo repository, and the issue is the dedicated
   demo issue. The existing propose → verify workflow can then prepare a
   draft PR; publication still requires its server-held approval, which
   autopilot cannot mint or bypass.
5. **No fix scope** — complete, but fixing is off or outside the demo
   scope. Recorded only; a maintainer can run the flow manually.

Actions appear under Settings → Maintainer autopilot → Recent autopilot
activity. A GitHub comment is attempted only when automatic comments are
opted in and the bot is configured; otherwise the action is recorded
locally and marked as not posted. Autopilot never posts as the user.

## Pull request flow

1. Autopilot reviews each new revision once and saves the verdict
   (`approve`, `request_changes`, or `discuss`).
2. With automatic comments on, one bot suggestion may be posted.
3. When the author pushes a fix or a maintainer comments
   `@proofpatcher review` (while `kernel:mentions` runs), the new revision
   is reviewed again; replies to bot comments are tracked as follow-ups.
4. With auto-merge on, the merge gate runs after a completed review.

## Merge gate

A merge happens only when **all** of these hold for the exact reviewed
revision; every failing reason is recorded with the attempt:

- auto-merge is on for the repository;
- the PR is open and not already merged;
- the autopilot review completed and recommends **approve**;
- every GitHub check run on the head commit completed successfully
  (failing, pending, missing, or unreadable checks block the merge);
- no unresolved review threads (an unreadable thread count blocks it);
- the branch still points at the reviewed commit;
- if the PR is a draft, auto-ready is on (it is marked ready first).

The attempt is written to the merge ledger before merging. A revision
whose merge outcome is merged, blocked after an attempt, or unknown is
never retried automatically; an unknown outcome must be checked on GitHub
before any manual retry. Manual merge keeps its existing confirmation and
exact-head check.

## Running it

```bash
php artisan kernel:select-repo --enable-review owner/repository
php artisan kernel:autopilot --repo=owner/repository --minutes=10
# or, as a background worker:
php artisan review:poll --daemon
```

The worker only runs while the local process runs; there is no webhook.
Without GitHub App bot credentials, comments are not posted and reviews
stay local. Without checks on a repository's PRs, auto-merge stays
blocked by design.
