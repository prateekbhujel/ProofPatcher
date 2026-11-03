"""Experimental isolated transport for one stateless orderTotal invocation.

Not wired into the application and not an oracle or an attestation. Candidate
stdout is untrusted: a program can print a plausible answer and exit. This
module only obtains a bounded observed integer from a fresh sandbox. The caller
must keep the trusted harness and its expected values outside that sandbox.
"""

from __future__ import annotations

import asyncio
import base64
import json
import re

IMAGE = "php@sha256:d716009046f4acac161ed7a14921111398228c2311696f132686be8613cb19ab"
MAX_SOURCE_BYTES = 65536
MAX_INPUT_BYTES = 4096
MAX_OUTPUT_BYTES = 4096
SANDBOX_LIFETIME_SECONDS = 30

_WORKER = r'''$source = base64_decode($argv[1], true);
$input = base64_decode($argv[2], true);
if ($source === false || $input === false) { exit(2); }
$items = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
if (!is_array($items)) { exit(2); }
$path = '/tmp/order-total-candidate.php';
if (file_put_contents($path, $source) === false) { exit(2); }
ob_start();
require $path;
if (!function_exists('orderTotal')) { exit(2); }
$result = orderTotal($items);
if (!is_int($result) || ob_get_length() !== 0) { exit(2); }
ob_end_clean();
echo $result, "\n";
'''


class CandidateTransportError(Exception):
    """A candidate did not produce one bounded observable integer."""


def make_sandbox_spec(source: bytes, items: list) -> tuple[tuple[str, ...], dict]:
    if not isinstance(source, bytes) or not source or len(source) > MAX_SOURCE_BYTES:
        raise ValueError("Invalid candidate source size")
    if not isinstance(items, list):
        raise ValueError("Expected an array of inputs")
    try:
        payload = json.dumps(items, separators=(",", ":"), allow_nan=False).encode("utf-8")
    except (TypeError, ValueError, OverflowError) as exc:
        raise ValueError("Invalid input") from exc
    if len(payload) > MAX_INPUT_BYTES:
        raise ValueError("Input too large")
    command = ("php", "-n", "-r", _WORKER, base64.b64encode(source).decode("ascii"),
               base64.b64encode(payload).decode("ascii"))
    options = {"runtime": "gvisor", "block_network": True, "timeout": SANDBOX_LIFETIME_SECONDS,
               "cpu": (0.25, 0.25), "memory": (256, 256), "secrets": [], "volumes": {},
               "network_file_systems": {}, "env": {}, "include_oidc_identity_token": False}
    return command, options


async def _collect(stream, combined: list[int]) -> str:
    chunks = []
    async for chunk in stream:
        if not isinstance(chunk, str):
            raise CandidateTransportError("Invalid sandbox output")
        combined[0] += len(chunk.encode("utf-8"))
        if combined[0] > MAX_OUTPUT_BYTES:
            raise CandidateTransportError("Sandbox output limit exceeded")
        chunks.append(chunk)
    return "".join(chunks)


async def observe_candidate(source: bytes, items: list, create_sandbox, *, deadline: float = 45.0) -> int:
    """Observe one result; create_sandbox must launch the supplied fixed spec.

    Each invocation creates and terminates a new sandbox. This has not been
    verified live; the app remains blocked from using this to authorize work.
    """
    command, options = make_sandbox_spec(source, items)
    if not 0 < deadline <= 60:
        raise ValueError("Invalid deadline")
    sandbox = None
    try:
        async with asyncio.timeout(deadline):
            sandbox = await create_sandbox(command, options)
            combined = [0]
            async with asyncio.TaskGroup() as tasks:
                stdout_task = tasks.create_task(_collect(sandbox.stdout, combined))
                stderr_task = tasks.create_task(_collect(sandbox.stderr, combined))
                tasks.create_task(sandbox.wait())
            stdout, stderr = stdout_task.result(), stderr_task.result()
            if sandbox.returncode != 0 or stderr or not re.fullmatch(r"-?(?:0|[1-9][0-9]*)\n", stdout):
                raise CandidateTransportError("Invalid sandbox result")
            value = int(stdout)
            if not -(2**63) <= value < 2**63:
                raise CandidateTransportError("Invalid sandbox integer")
            return value
    except TimeoutError as exc:
        raise CandidateTransportError("Sandbox deadline exceeded") from exc
    finally:
        if sandbox is not None:
            try:
                await asyncio.wait_for(sandbox.terminate(), timeout=5)
            except Exception as exc:
                # Provider lifetime is bounded, but cleanup is not confirmed.
                raise CandidateTransportError("Sandbox cleanup not confirmed") from exc


def modal_factory(app):
    """Build a Modal factory with the fixed, reviewed image reference."""
    import modal
    image = modal.Image.from_registry(IMAGE, add_python="3.11")

    class SandboxAdapter:
        def __init__(self, sandbox):
            self.sandbox = sandbox
            self.stdout = sandbox.stdout
            self.stderr = sandbox.stderr

        @property
        def returncode(self):
            return self.sandbox.returncode

        async def wait(self):
            await self.sandbox.wait.aio()

        async def terminate(self):
            await self.sandbox.terminate.aio(wait=True)

    async def create(command, options):
        sandbox = await modal.Sandbox.create.aio(*command, app=app, image=image, **options)
        return SandboxAdapter(sandbox)

    return create
