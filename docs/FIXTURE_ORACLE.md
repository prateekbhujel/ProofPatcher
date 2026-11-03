# Fixture oracle isolation

ProofPatcher's application verifier supports pinned fixture candidates. It does not currently authorize arbitrary generated code for verified publication. A worktree alone is not a security sandbox, and putting candidate code and its tests in the same sandbox exposes the test oracle to that candidate.

An experimental runner explores a narrower approach for the pinned, stateless order-total fixture. The trusted harness holds the expected results, while each candidate call runs in a separate Modal sandbox. Only that call's input is sent to the candidate. The sandbox is configured with network access blocked, no mounted secrets or volumes, and limits on runtime and output. The coordinator runs the pinned harness, compares the observed responses, and attempts to terminate every sandbox.

The runner is independent of the application verification and publication flow. It does not produce a publication authorization. The fixture results cover only the tested cases: candidate-controlled output can imitate a plausible answer, and the transport cannot prove the function returned normally. A fresh sandbox per call also changes the behavior of stateful functions.

## Optional smoke test

```bash
python scripts/check_modal_oracle_fixture.py --execute
```

This command requires a configured Modal SDK and credentials. It launches billable sandboxes. Run it only when you intend to use provider resources; the application does not require it to start.

## Requirements for application integration

- Bind any result to the repository, issue, base revision, candidate bytes, pinned harness, runner and provider execution.
- Check the baseline, candidate and negative controls independently, and distinguish assertion failures from runtime or transport failures.
- Fail closed on stale revisions, missing evidence, abnormal output, timeouts, unsuccessful cleanup and ambiguous publication results.
- Keep private source, test inputs and credentials out of public evidence and model requests unless explicitly authorized.
- Treat finite observations and receipt integrity as evidence for their stated scope, not proof of general correctness or safety.
