# Hari kernel — verdict, design, and threat model

Date: 2026-11-01. Status: FULL RUN COMPLETE. Five skills live
(`budget.status`, `evidence.verify`, `review.result`, `verify.candidate`,
`verify.patch` v1) across four provider abilities and five borrowers;
17 consumers migrated; boundaries enforced by ArchitectureTest; 21/21
live attack probes pass; full web + terminal flows verified green.
No model spend.

## 6. Full-run notes (same date, later)

- Skill inventory: budget (counters), evidence (receipt VALID/INVALID as
  data), review (stats | exact revision | filtered list, explicit row
  projection byte-compatible with the old Eloquent JSON), verify.candidate
  (5-gate, guards re-run from identical `fromRaw` code — never mirrored),
  verify.patch (4-gate CSS, `CssPatch::fromArray` inside the skill).
- Engines stay caller-supplied via `withVerifier()`: the registry gates
  WHO may invoke; runner/project selection remains a deployment/test
  concern with the caller. This preserved every protected integration
  test byte-identical in setup; the suite proves the money path.
- Deliberately NOT migrated (audited, with reasons): model-spend paths
  (`RequestBudget::run` inside agents — a spend skill is a dangerous
  surface needing its own design), autopilot opt-in settings store
  (config, not outcomes), `ReviewJobController` snapshot verify
  (GitHub-coupled store API), `OwnedCaseVerifier::KNOWN_*` constants
  (data, not behavior).
- `tests/bootstrap.php` boots a frozen global with a throwaway budget so
  the suite is deterministic and never touches developer state.
- Invariants enforced by `ArchitectureTest`: hari purity; no direct
  `VerifyCandidateUseCase` construction, `AutoReviewResult::`,
  `->verify(` (except ReviewJob), runtime `status()` reads, or `Receipt`
  use outside skills in HTTP/console/support layers.

## 1. Verdict: do we have a kernel today?

**No.** Today ProofPatcher is a **modular monolith** with a genuinely
generic substrate and clean emerging seams — not a kernel with abilities.

Evidence (measured, not guessed):

- `app/Evidence` imports nothing. `app/Verifier` (9 files, ~2270 lines)
  imports only Evidence/Patch/Support plus Symfony — zero GitHub
  references. That is the hari candidate core.
- `app/GitHub` (18 files) is imported by Agent (4 files),
  Application/Workbench (3), Console (6), Http (8), Models (1),
  Services (1), Support (2). The HTTP layer is 18/18 GitHub-coupled.
- What the codebase calls "Kernel" (`KernelCli`, `kernel:*` commands,
  Kernel desktop/terminal) is actually a **shell** — the operator UX
  metaphor. Hari is a different layer: the trusted core *under* abilities,
  not the terminal. We keep both names; they refer to different things.

## 2. Concept check (your vision, with corrections)

Your vision is coherent and we are building toward it, with three
corrections:

1. **Borrowing must be declared, versioned, and capability-checked.**
   "B borrows skills from A" is the right shape, but unguarded borrowing
   is a confused-deputy hole: a skill running under B's authority with A's
   assumptions. So: a skill declares its side effects and required
   capabilities; an ability manifest declares what it provides, what it
   borrows (with version constraints), and what it needs. The registry
   enforces all three and fails closed. This mirrors microkernel
   thinking: minimal trusted core (isolation, scheduling, IPC) with
   everything else as untrusted servers
   ([microkernel](https://en.wikipedia.org/wiki/Microkernel)). Our
   mapping: isolation = owner-scoped stores + budget guards + fail-closed
   checks; scheduling = poll loops / workers / attempt budgets;
   IPC = the skill registry in-process, MCP stdio servers out-of-process.
2. **Small analogy fix:** Linux is monolithic, not a microkernel; Ubuntu
   and Fedora are distributions (userlands) on one kernel. Your
   "ProofPatch is one OS on hari, AccPatch another" maps to
   distributions/abilities on a minimal core — which is exactly the
   microkernel shape above. Hari stays minimal; abilities stay dumb.
3. **Full extraction is a multi-day migration, not one turn.**
   Hari as a standalone package with ProofPatch and AccPatch as pure
   abilities means moving GitHub/HTTP/domain code behind skill contracts.
   This turn ships the rigid part that makes that migration possible:
   the contracts, the enforcing registry, and one real skill with two
   real borrowers proving the pattern. No speculative abilities.

## 3. Contracts (namespace `Hari\`, pure PHP, zero deps)

- `Hari\Skill`: `id()` (`name.major`, e.g. `budget.status`),
  `version()` (major int), `capabilities()` (required caps),
  `describe()` (side-effect declaration), `invoke(array $input): array`.
  Skills are deterministic, typed at the boundary, and never touch the
  network except through a declared capability the registry granted.
- `Hari\Ability`: manifest — `name()`, `version()`, `provides()`
  (skill ids), `borrows()` (id → major constraint), `needs()`
  (capabilities). Pure data, no behavior.
- `Hari\Registry`: explicit `registerAbility()` + `provideSkill()`;
  `resolve($skillId, $major, $borrower)` enforces: borrower manifest
  exists, borrow declared, major matches, borrower holds every
  capability the skill requires. `freeze()` after boot; frozen registry
  rejects further mutation. All failures throw typed `Hari\HariError`
  with a machine-readable code — never null, never silent.
- `Hari\Hari`: static runtime holder (default registry + freeze).
  Tests use isolated `new Registry()` instances; the global never leaks
  between tests.

Namespace choice is deliberate: `Hari\` imports nothing from
`ProofPatch\`, enforced by `ArchitectureTest`. Extraction later is a
directory move plus a composer autoload line, not a rewrite.

## 4. Threat model (what is and isn't guaranteed)

**Guaranteed in-process:** undeclared borrows fail; version mismatches
fail; missing capabilities fail; duplicate skill versions fail; frozen
registry rejects mutation; malformed ids fail; skill exceptions surface
typed without leaking paths/internals to callers that must not see them.

**Explicitly NOT guaranteed:** in-process PHP cannot sandbox classes —
a hostile class inside the repo can always instantiate what it wants.
The registry is a *policy* boundary (auditable, tested), not a
*sandbox* boundary. True isolation for untrusted third-party abilities
needs a process boundary: one MCP stdio server per ability, OS user
separation, no shared filesystem. That is documented future work, not
a silent assumption. Skills from outside this repo MUST NOT be
registered until that boundary exists.

## 5. Non-goals

No auto-discovery (explicit registration is auditable); no web
transport for skills (see `docs/MCP_SPIKE_EVAL.md`); no write skills
until consent/budget/GitHub guards are skill-shaped; no second ability
until a concrete consumer exists.

## 7. Hari and ProofPatcher today (Nov 2026)

Names, pinned so they stop drifting:

- **Hari** is the in-process coordination core: the `Hari\Registry`,
  the ability manifests, and the skill contracts. It gates WHO may
  invoke WHAT (declared borrows, pinned majors, capabilities) and
  fails closed. It is not an operating-system kernel and is never
  presented as one.
- **ProofPatcher** is the application on Hari: repository maintenance
  and issue-solving abilities (`proofpatch-budget`,
  `proofpatch-evidence`, `proofpatch-review`, `proofpatch-verify`)
  plus their borrowers (`web`, `workbench`, `kernel-cli`,
  `telemetry`, `mcp`).
- **Kernel** on screen (desktop, `kernel:*` commands, `OsStatus`) is
  the operator shell: connection state, next step, and status. It
  reads Hari skills but is not Hari.

Connection state follows one rule on every surface: the web session
and the terminal share the file sign-in; nothing shows "connected"
without a verified GitHub user; repository selection is shared while
it stays inside the current grant. The shared pieces are
`GitHubConnectionState`, `ConnectionNextStep`, and `AppRedirectTarget`
in `app/Application/Settings`.
