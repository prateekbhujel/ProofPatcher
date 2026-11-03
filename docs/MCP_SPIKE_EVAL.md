# Local MCP interface

Current state: November 3, 2026.

`php artisan mcp:start proofpatch` exposes three read-only tools over the existing Hari skills: budget status, review results and receipt integrity verification. `app/Mcp/Servers/ProofPatchServer.php` is the current inventory. No tool proposes patches, runs candidate code, publishes comments or changes GitHub. HTTP transport remains deferred.

The server currently advertises version `0.2.0`; this is separate from the product's initial showcase version. The original one-tool spike below is preserved as history.

<details>
<summary>Historical November 1 spike</summary>

# MCP spike evaluation (slice 7)

Date: 2026-11-01. Time-boxed spike, verified live. No model spend.

## Question

Should ProofPatcher expose its state to coding agents via
[Laravel MCP](https://laravel.com/docs/13.x/mcp), and in what shape?

## Findings

- `laravel/mcp` v1.1.2 fits this stack exactly: PHP `^8.2` (we run 8.4),
  `illuminate/*` `^11.45.3|^12.41.1|^13.0` (we run 13). Only illuminate /
  symfony deps, no new network services.
- Two transports: **local** (stdio Artisan command, same trust as the CLI)
  and **web** (HTTP, needs Sanctum token or Passport OAuth per the docs).
- Authoring is thin: `Server` subclass + `Tool` subclasses with input
  schemas, validation, and DI. Tools are unit-testable without a booted app
  (`new Tool($dep)` + `handle(new Request($args))`).
- Package discovery needed one manual `php artisan package:discover`
  before `vendor:publish --tag=ai-routes` worked.

## Decision: ADOPT (local stdio, read-only) / DEFER the rest

- **Adopt**: local server `proofpatch` (`routes/ai.php`, `Mcp::local`) with
  read-only tools over existing seams. Shipped one: `budget-status-tool`
  delegates to `RequestBudget::status()` and returns an explicit allowlist
  of counters (no keys, tokens, or paths). Fails closed with a generic
  message. Covered by `tests/Unit/McpBudgetStatusToolTest.php` (exact
  counters, no-secret scan, symlink failure).
- **Defer**: web transport (AGENTS.md forbids unauthenticated endpoints;
  needs Sanctum/Passport first — noted in `routes/ai.php`), and all write
  tools (propose/verify/publish would need the consent, budget, and GitHub
  machinery; no concrete consumer yet).
- **Rejected**: hand-rolled stdio JSON-RPC. Same protocol burden, no schema
  validation, no inspector, more code to maintain.

## Live verification

`/tmp/pp_mcp_probe.py` drove `php artisan mcp:start proofpatch` over stdio:
`initialize` → server `ProofPatcher 0.1.0`; `tools/list` → exactly 1 tool;
`tools/call budget-status-tool` → live ledger values
(`attempts=19`, `reservedMicros=344804`, matching slice-6 post-run state).

## Follow-ups (not started)

- Second read-only tool: latest auto-review result for a PR
  (`AutoReviewResult::findForRevision`), same allowlist discipline.
- Client config snippet for the owner's agents once a consumer exists.

</details>
