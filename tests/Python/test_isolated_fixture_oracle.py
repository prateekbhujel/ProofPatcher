from __future__ import annotations

import asyncio
import base64
import json
import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))
import isolated_fixture_oracle as oracle

HARNESS = (ROOT / "tests" / "Fixtures" / "owned-review-case" / "tests" / "order_total.php").read_bytes()
SOURCE = b"<?php function orderTotal($items) { return 0; }"


async def stream(text):
    if text:
        yield text


class FakeSandbox:
    def __init__(self, value, *, stall=False):
        self.stdout = stream(value)
        self.stderr = stream("")
        self.returncode = 0
        self.stall = stall
        self.terminated = False

    async def wait(self):
        if self.stall:
            await asyncio.sleep(10)

    async def terminate(self):
        self.terminated = True


class FixtureIntegrationTest(unittest.IsolatedAsyncioTestCase):
    async def run_fake(self, mode, **kwargs):
        sandboxes = []
        calls = []

        async def create(command, options):
            items = json.loads(base64.b64decode(command[-1]))
            calls.append((items, options))
            if mode == "baseline":
                value = sum(item["unit_price"] * max(1, item["quantity"]) for item in items)
            elif mode == "repair":
                value = sum(item["unit_price"] * item["quantity"] for item in items)
            else:
                value = 0
            sb = FakeSandbox(f"{value}\n", stall=(mode == "stall"))
            sandboxes.append(sb)
            return sb

        result = await oracle.run_fixture(HARNESS, SOURCE, create, **kwargs)
        self.assertTrue(all(sb.terminated for sb in sandboxes))
        return result, calls

    async def test_real_pinned_harness_compares_bounded_fake_observations(self):
        baseline, _ = await self.run_fake("baseline")
        repaired, calls = await self.run_fake("repair")
        negative, _ = await self.run_fake("negative")
        self.assertEqual(baseline["status"], "fixture_failed")
        self.assertEqual(repaired["status"], "fixture_passed")
        self.assertEqual(negative["status"], "fixture_failed")
        self.assertEqual(len(calls), 3)
        self.assertTrue(all(options["block_network"] for _, options in calls))
        self.assertNotIn("expected", str(repaired))
        self.assertIn("unverified", repaired["assurance"])

    async def test_deadline_aborts_and_terminates_fake_sandbox(self):
        result, _ = await self.run_fake("stall", deadline=0.1)
        self.assertEqual(result["status"], "aborted")

    async def test_wrong_harness_rejected_before_provider(self):
        async def create(*_):
            self.fail("must not call provider")
        with self.assertRaises(ValueError):
            await oracle.run_fixture(HARNESS + b"\n", SOURCE, create)
