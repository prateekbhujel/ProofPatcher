# Bounded Test-Oracle Boundary Plan

Status: An experimental, fixture-only Modal transport and coordinator have been exercised independently of the application. The existing production fail-closed guard remains in force. No arbitrary candidate execution or publication has been enabled by this work.

On November 3, 2026, a limited live Modal experiment used ten short-lived sandboxes: a network and environment probe, followed by three calls each for the baseline, a hand-written repair, and a constant-zero negative control. The baseline and negative control failed the pinned checks; the repair passed. The tested outbound connection failed, probed host files were absent, and no secret-like environment variable names were seen. Sandbox termination was awaited, and the probe apps subsequently showed as stopped. This is evidence for those specific observations, not a complete isolation audit. Provider cost was not available at test time.

The optional script `python scripts/check_modal_oracle_fixture.py --execute` launches billable provider sandboxes and requires configured Modal credentials. The script and transport are experimental and are not connected to the app or the GitHub publication gate. The live experiment used equivalent calls; the subsequently saved script itself has not yet been run live.

## Aim

For the pinned order-total fixture, run the original pinned PHP harness as trusted code. Its `orderTotal()` dependency is a bridge; each call sends only input items to a separate sandbox containing the candidate. The harness alone owns the test cases, expected values, comparisons, failure count, and final decision. The candidate never shares PHP memory with the test harness or oracle.

This demonstrates results for the pinned test cases only. It does not prove a candidate is generally correct, safe, independently authored, or suitable to merge. A separate sandbox per call changes the semantics of stateful functions and must be clearly scoped to stateless `orderTotal` candidates. Generic repositories and arbitrary PHP projects are outside this design.

## Proposed Boundary

1. **Harness Integrity & Parameter Validation**:
   Validate the pinned harness digest, target path, candidate size, PHP syntax, and resource limits. Construct a fresh private directory (`0700`) containing the exact trusted harness and a trusted bridge at the harness's expected include path. The candidate is never loaded or executed by the trusted host process. Never mount the harness, private files, credentials, or workspace in the candidate sandbox.

2. **Process Separation**:
   Start the pinned harness in a limited process with a stripped environment. The bridge sends a length-bounded JSON array over a dedicated pipe to the trusted coordinator and waits for one bounded response. Do not let repository metadata, model output, or candidate code select the harness, executable, channels, or sandbox image.

3. **MicroVM Isolation**:
   For each call, create a fresh Modal sandbox with the reviewed, pinned runtime image, `runtime="gvisor"`, `block_network=True`, no secrets, volumes, mounts, or inherited environment, and bounded CPU, memory, and wall time. Run only the candidate worker there. Check the runtime's actual behavior and available process permissions in a live authorized test; a requested setting is not proof it took effect. Modal describes gVisor as a sandbox boundary from the host and other workloads: https://modal.com/docs/guide/sandbox-networking.

4. **Bounded I/O and Concurrency**:
   The coordinator sends only the encoded arguments to the candidate and concurrently drains stdout and stderr with small hard byte limits and a per-call deadline. Reject malformed, oversized, truncated, timed-out, multi-value, or non-integer output, nonzero exit, and transport failures. On every path terminate the sandbox and confirm cleanup; unexpected outcomes fail closed. Never wait for process exit before draining output. Cap total calls, input sizes, and overall runtime as well as per-call resources.

5. **Untrusted Candidate Stdout & Return Ambiguity**:
   Return only the integer response to the harness. The harness executes its own unchanged assertions; the trusted coordinator verifies process status and evidence hashes and records the pinned harness, runtime, and limits. Successful output means only that observed sandbox responses matched those assertions.
   > **Critical Limitation**: Do not claim that the candidate function returned normally. A candidate can write a plausible integer and call `exit((string) $total)`. A result pipe controlled by candidate code cannot attest how it produced that value or prove normal function return. `ob_start()` and JSON parsing do not prevent early exit. Treat candidate stdout as untrusted output.

6. **Server-Held Authorization & Gate Integrity**:
   Bind a fresh server-held publication authorization to the precise candidate bytes, harness digest, base revision, sandbox result, and current authorization. Invalidate old receipts produced by the shared-process oracle. Do not issue publication authorization for a mock runner or incomplete execution. Keep the fail-closed pinned candidate guard in place until this verdict protocol is verified.

## Required Tests Before Enabling

- **Actual Pinned Harness**: Baseline fails; known repair passes; constant-zero negative control fails; any additional protected cases execute as written.
- **Adversarial Resilience**: Candidate global mutation (`$GLOBALS['expected']`, `$GLOBALS['failed']`), test-file writes, supervisor file reads, process signalling, `exit(0)`, forged output, forged completion text, malformed or oversized JSON, exceptions, fork, and output floods all fail closed or are reported only within their exact limited claim. The natural-return forgery must remain identified as unprovable from candidate-controlled stdout alone.
- **Sandbox Boundary Verification**: Confirm the candidate cannot read harness bytes, environment secrets, model keys, other runs, or local mounts; confirm network blocking and no inherited credentials inside the real sandbox. Test under the exact selected image and runtime, not a host process double.
- **Operational Safety**: Verify deadlines, concurrent draining, process termination, child cleanup, and bounded costs under timeouts and flood conditions. Check stale revisions, changed files, prior receipts, repeated approvals, and duplicate publication.
- **Fail-Closed State**: Keep browser-driven end-to-end and private repository handling marked unverified until separately tested.

## Prototype Limitations

`tests/Support/OrderTotalOraclePrototype.php` only checks the shared-memory issue with manually authored fixtures. Its worker and harness use the same host user, can access each other's files, have no privilege separation, and are not resource-sandboxed. A child can print a convincing integer and exit before returning; `testPrototypeCannotProveCandidateReturnedNormally` explicitly demonstrates this. The prototype is never called by application code and is not a basis for enabling model output.
