# Maintainer autopilot

Autopilot reviews pull requests and triages issues in enabled public repositories while the local worker is running. Each action has its own per-repository setting. Automatic review and bot comments are separate opt-ins. Automatic ready, merge and close are unavailable.

## Switches

All switches live in **Settings** and apply only to public repositories the
signed-in user owns and has installed:

| Switch | Where | Effect |
| --- | --- | --- |
| Autopilot reviews | Settings → Autopilot reviews | Muse reviews new/updated open PR revisions while a local worker runs |
| Automatic comments | Settings → Automatic GitHub comments | The GitHub App bot may post suggestions; requires bot credentials |
| Fix demo issue | Settings → Maintainer autopilot | Complete issues in the dedicated demo scope are marked fix-ready |
| Auto-ready | Settings → Maintainer autopilot | Unavailable while safeguards are reviewed |
| Auto-merge | Settings → Maintainer autopilot | Unavailable while safeguards are reviewed |
| Auto-close | Settings → Maintainer autopilot | Unavailable while safeguards are reviewed |
| Spend caps | Settings → Autopilot budget | Optional request/spend limits for all autopilot model calls |

Manual mode uses the same flows: a maintainer click with its existing
confirmation replaces the autopilot switches, nothing else changes.

## Issue flow

While `kernel:autopilot` or `review:poll` runs, repositories with autopilot
reviews on **and** at least one maintainer switch or automatic comments on
are also watched for open issues. At most one action is recorded for each
issue-text revision (title and body):

1. **Related PR** — an open or merged PR mentioning the
   issue (`#n` in title or body) is reported for a maintainer to assess; a mention alone does not prove a fix.
2. **Duplicate** — the title closely matches another open issue.
3. **Needs info** — the deterministic completeness check finds missing
   reproduction steps, expected-vs-actual behavior, environment, or
   evidence, and the missing questions are listed.
4. **Fix eligible** — the issue is complete, the repository is the
   configured dedicated demo repository, and the issue is the dedicated
   demo issue. This records eligibility only; it does not request a fix, run checks or open a pull request. The separate manual workflow has its own verification and publication approval.
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
4. A maintainer decides whether to merge or close. Automatic merge and close are suspended.

## Automatic actions

Automatic ready, merge and close are suspended. The current implementation checks pull-request readiness but does not enable these actions. Revision-race protection and live testing are required. Existing saved opt-ins do not
bypass this suspension. Manual actions retain their confirmation.

## Terminal (TUI) parity

The same switches live in the terminal: `php artisan kernel` → **Maintainer
autopilot** toggles fix / ready / merge / close for the selected
repository (ready, merge and close are unavailable), `kernel:status` shows stored preferences, and `kernel:autopilot`
prints issue actions plus merge/close outcomes per cycle. Manual merge
and close stay where they already were: the pull request page's Merge /
Close buttons with their confirmation step.

## Voice dictation

The browser may use its own speech-recognition service. When browser recognition
is unavailable, the server can use a locally installed Whisper CLI. Provider
transcription is not connected or validated and is not a demo claim.

## Large-repository demo

To exercise this against a big codebase, enable autopilot reviews plus a
maintainer switch on a large public repository you own (a busy fork
works), set an autopilot spend cap, and run
`php artisan kernel:autopilot --repo=owner/big-repo --minutes=10`.
Reviews skip already-reviewed revisions, issue actions are recorded once
per revision, and oversized diffs keep the existing bounded/truncated
handling. The pagination tests use mocked data; whole-repository autonomous
repair has not been demonstrated.

## Running it

```bash
php artisan kernel:select-repo --enable-review owner/repository
php artisan kernel:autopilot --repo=owner/repository --minutes=10
# or, as a background worker:
php artisan review:poll --daemon
```

The worker only runs while the local process runs; there is no webhook.
Without GitHub App bot credentials, comments are not posted and reviews
stay local. Automatic merge and close remain unavailable.
