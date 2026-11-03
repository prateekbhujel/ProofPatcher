# Hari

Hari is the coordination core beneath ProofPatcher. It connects explicitly registered abilities to versioned skills, checks what each ability may borrow, and keeps those contracts in one place. ProofPatcher is the application built on Hari: its web workbench and terminal use the registry to reach the capabilities they need.

Hari is an in-process PHP registry. The kernel and operating-system names describe how the product is organized; Hari does not replace the host operating system, isolate untrusted code, or provide persistent learned memory.

## How it works

1. An **ability** declares the skills it provides, the skills it borrows, and the capabilities it needs.
2. A **skill** declares its identifier, major version, required capabilities, and behavior.
3. The **registry** registers those contracts explicitly. When an ability asks for a skill, Hari checks that the borrow is declared, the major version matches, and the required capabilities are held.
4. The application freezes the registry after boot so contracts cannot be changed while it is running.

Unknown abilities, undeclared borrows, version mismatches, missing capabilities and duplicate skill versions fail with a `HariError`. Registry inspection lists manifests without invoking their skills.

## Available skills

| Skill | Use |
| --- | --- |
| `budget.status` | Read local model-usage counters |
| `evidence.verify` | Check a saved evidence receipt's integrity |
| `review.result` | Read saved automatic-review outcomes |
| `verify.candidate` | Run the supported candidate-verification workflow |
| `verify.patch` | Run the controlled checkout-verification workflow |
| `engineering.workflow` | Supply structured guidance to review, issue and task requests |

`proofpatch-review` and `workbench` borrow `engineering.workflow`. This guidance asks for concrete behavior, causes, edge cases and evidence; it does not run tests or make a model response verified. GitHub access, model requests, verification and publication retain their own permissions and checks.

## Boundaries

Hari enforces contracts between registered abilities. It is not a security sandbox: PHP code running in the same process can access the host according to its process permissions. Untrusted repository code must run in a separately configured isolation environment, with its test oracle protected from candidate code. A worktree alone does not provide that isolation.

The implementation is in `app/Hari/`, `app/Abilities/`, and `app/Abilities/HariServiceProvider.php`. `php artisan kernel:status` shows the current registry and connection status. The local MCP server borrows read-only skills; see [Local MCP interface](MCP.md).
