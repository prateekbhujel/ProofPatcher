#!/usr/bin/env python3
"""Run finite checks for one candidate; only the pinned fixture is supported.

Reads candidate bytes as JSON from stdin. This is not an attestation of code
correctness or permission to publish. Each observed call uses a fresh Modal
sandbox; the candidate never receives the harness or its expected values.
"""
from __future__ import annotations

import asyncio
import hashlib
import json
import sys
from pathlib import Path

from isolated_fixture_oracle import HARNESS_SHA256, run_fixture
from modal_oracle_candidate import modal_factory

ROOT = Path(__file__).resolve().parents[1]
BASELINE_SHA256 = "54bc37fb2b005badcf309c5a7091ba6a2e704fc54c8eff9e9c7afee7e4054859"
NEGATIVE = b"<?php function orderTotal(array $items): int { return 0; }"
MAX_INPUT_BYTES = 100000


async def observe(source: bytes, create_sandbox) -> dict:
    fixture = ROOT / "tests" / "Fixtures" / "owned-review-case"
    harness = (fixture / "tests" / "order_total.php").read_bytes()
    baseline = (fixture / "src" / "OrderTotal.php").read_bytes()
    if hashlib.sha256(harness).hexdigest() != HARNESS_SHA256 or hashlib.sha256(baseline).hexdigest() != BASELINE_SHA256:
        raise ValueError("Fixture integrity check failed")
    if not source or len(source) > 65536:
        raise ValueError("Invalid candidate source")
    results = {}
    for name, code in (("baseline", baseline), ("negative", NEGATIVE), ("candidate", source)):
        result = await run_fixture(harness, code, create_sandbox)
        results[name] = result
        if result["status"] == "aborted":
            break
    statuses = {name: item["status"] for name, item in results.items()}
    calls = {name: item["calls"] for name, item in results.items()}
    expected = {"baseline": "fixture_failed", "negative": "fixture_failed", "candidate": "fixture_passed"}
    return {
        "status": "passed" if statuses == expected and calls == {key: 3 for key in expected} else "failed",
        "candidateSha256": hashlib.sha256(source).hexdigest(),
        "harnessSha256": HARNESS_SHA256,
        "baselineSha256": BASELINE_SHA256,
        "baseline": statuses.get("baseline", "aborted"),
        "negative": statuses.get("negative", "aborted"),
        "candidate": statuses.get("candidate", "aborted"),
        "calls": {key: calls.get(key, 0) for key in expected},
        "scope": "finite_local_fixture",
    }


async def main():
    try:
        request = json.loads(sys.stdin.buffer.read(MAX_INPUT_BYTES + 1))
        source = request["candidate"].encode("utf-8")
        import modal
        app = modal.App("proofpatch-fixture-observations")
        async with app.run.aio():
            result = await observe(source, modal_factory(app))
        print(json.dumps(result, separators=(",", ":")))
    except Exception:
        # Do not emit provider details or candidate source into application logs.
        print('{"status":"aborted"}')
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
