from __future__ import annotations

import asyncio
import importlib.util
import pathlib
import sys
import types
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("modal_oracle_candidate", ROOT / "scripts" / "modal_oracle_candidate.py")
adapter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)


async def stream(chunks):
    for chunk in chunks:
        yield chunk


class FakeSandbox:
    def __init__(self, stdout=("330\n",), stderr=(), code=0, wait_forever=False, cleanup_fails=False):
        self.stdout = stream(stdout)
        self.stderr = stream(stderr)
        self.returncode = code
        self.wait_forever = wait_forever
        self.cleanup_fails = cleanup_fails
        self.terminated = False

    async def wait(self):
        if self.wait_forever:
            await asyncio.sleep(10)

    async def terminate(self):
        self.terminated = True
        if self.cleanup_fails:
            raise RuntimeError("failure")


class ModalOracleCandidateTest(unittest.IsolatedAsyncioTestCase):
    async def observe(self, sandbox, *, deadline=1):
        captured = []

        async def factory(command, options):
            captured.append((command, options))
            return sandbox

        result = await adapter.observe_candidate(b"<?php function orderTotal($x){return 330;}", [], factory, deadline=deadline)
        return result, captured

    async def test_spec_is_fixed_and_sandbox_is_cleaned_up(self):
        sandbox = FakeSandbox()
        result, [(command, options)] = await self.observe(sandbox)
        self.assertEqual(result, 330)
        self.assertTrue(sandbox.terminated)
        self.assertEqual(command[:3], ("php", "-n", "-r"))
        self.assertEqual(options["runtime"], "gvisor")
        self.assertTrue(options["block_network"])
        self.assertEqual(options["secrets"], [])
        self.assertEqual(options["volumes"], {})
        self.assertEqual(options["network_file_systems"], {})
        self.assertEqual(options["env"], {})
        self.assertFalse(options["include_oidc_identity_token"])

    async def test_invalid_or_forged_protocol_fails_closed(self):
        for output, stderr, code in [(("330\n330\n",), (), 0), (("330",), (), 0),
                                     (("1e3\n",), (), 0), (("330\n",), ("error",), 0),
                                     (("330\n",), (), 1), ((str(2**63) + "\n",), (), 0)]:
            with self.subTest(output=output, stderr=stderr, code=code):
                sandbox = FakeSandbox(output, stderr, code)
                with self.assertRaises(adapter.CandidateTransportError):
                    await self.observe(sandbox)
                self.assertTrue(sandbox.terminated)

    async def test_output_flood_and_timeout_abort_and_cleanup(self):
        sandbox = FakeSandbox(("x" * 4097,))
        with self.assertRaises(ExceptionGroup):
            await self.observe(sandbox)
        self.assertTrue(sandbox.terminated)
        sandbox = FakeSandbox(wait_forever=True)
        with self.assertRaises(adapter.CandidateTransportError):
            await self.observe(sandbox, deadline=0.01)
        self.assertTrue(sandbox.terminated)

    async def test_cleanup_failure_cannot_return_success(self):
        sandbox = FakeSandbox(cleanup_fails=True)
        with self.assertRaisesRegex(adapter.CandidateTransportError, "cleanup"):
            await self.observe(sandbox)

    async def test_integer_output_cannot_prove_candidate_returned(self):
        # The sandbox can print a plausible answer and exit before returning.
        # The adapter only reports an observation and does not attest its origin.
        result, _ = await self.observe(FakeSandbox(stdout=("330\n",)))
        self.assertEqual(result, 330)

    def test_invalid_inputs_are_rejected(self):
        for source, items in [(b"", []), (b"x" * 65537, []), (b"x", {}), (b"x", [float("nan")]),
                              (b"x", ["x" * 4097])]:
            with self.subTest(source_len=len(source)):
                with self.assertRaises(ValueError):
                    adapter.make_sandbox_spec(source, items)

    async def test_modal_factory_uses_async_sdk_methods(self):
        calls = []

        class RawSandbox:
            stdout = stream(("330\n",))
            stderr = stream(())
            returncode = 0

        async def wait():
            calls.append("wait")

        async def terminate(*, wait):
            calls.append(("terminate", wait))

        raw = RawSandbox()
        raw.wait = types.SimpleNamespace(aio=wait)
        raw.terminate = types.SimpleNamespace(aio=terminate)

        async def create(*args, **kwargs):
            calls.append(("create", args, kwargs))
            return raw

        fake_modal = types.SimpleNamespace(
            Image=types.SimpleNamespace(from_registry=lambda image, **_: image),
            Sandbox=types.SimpleNamespace(create=types.SimpleNamespace(aio=create)),
        )
        with mock.patch.dict(sys.modules, {"modal": fake_modal}):
            factory = adapter.modal_factory(object())
            result = await adapter.observe_candidate(b"<?php", [], factory)
        self.assertEqual(result, 330)
        self.assertIn("wait", calls)
        self.assertIn(("terminate", True), calls)
        self.assertEqual(calls[0][2]["image"], adapter.IMAGE)
