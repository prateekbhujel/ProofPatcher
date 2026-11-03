"""Experimental trusted coordinator for the pinned order-total harness.

Not wired to the application or a publication gate. The trusted PHP process
loads only the pinned test and the fixed bridge below. Candidate code is sent
only to the injected sandbox transport, never executed by the coordinator.
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import os
import re
import shutil
import signal
import tempfile
from pathlib import Path

from modal_oracle_candidate import observe_candidate

HARNESS_SHA256 = "b8079fb1e7731fc763f0b009a11c2786d01b52cce08f7431768a64e8a5ef2cbf"
MAX_CALLS = 16
MAX_FRAME = 4096
MAX_OUTPUT = 16384

_BRIDGE = r'''<?php
declare(strict_types=1);
function orderTotal(array $items): int
{
    $json = json_encode($items, JSON_THROW_ON_ERROR);
    if (strlen($json) > 4096) { throw new RuntimeException('Input too large'); }
    fwrite(STDOUT, "PP_CALL " . $json . "\n");
    fflush(STDOUT);
    $line = fgets(STDIN, 4097);
    if ($line === false || !str_ends_with($line, "\n")) { throw new RuntimeException('Missing response'); }
    $value = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
    if (!is_int($value)) { throw new RuntimeException('Invalid response'); }
    return $value;
}
'''


class FixtureError(Exception):
    pass


async def run_fixture(harness: bytes, source: bytes, create_sandbox, *, deadline: float = 150.0) -> dict:
    """Compare sandbox observations to the finite checks in the pinned harness.

    The result is deliberately not an oracle attestation, publication permission,
    or proof of normal candidate return. Sandbox isolation has not been tested.
    """
    if not isinstance(harness, bytes) or hashlib.sha256(harness).hexdigest() != HARNESS_SHA256:
        raise ValueError("Unrecognized fixture harness")
    if not isinstance(source, bytes) or not source or len(source) > 65536:
        raise ValueError("Invalid candidate")
    if not 0 < deadline <= 180:
        raise ValueError("Invalid deadline")
    php = shutil.which("php")
    if os.name != "posix" or not php:
        raise RuntimeError("POSIX PHP CLI required")

    calls = 0
    output = bytearray()
    error_output = bytearray()
    proc = None
    with tempfile.TemporaryDirectory(prefix="proofpatch-trusted-oracle-") as work:
        root = Path(work)
        (root / "tests").mkdir(mode=0o700)
        (root / "src").mkdir(mode=0o700)
        (root / "tests" / "order_total.php").write_bytes(harness)
        (root / "src" / "OrderTotal.php").write_text(_BRIDGE, encoding="utf-8")
        try:
            async with asyncio.timeout(deadline):
                proc = await asyncio.create_subprocess_exec(
                    php, "-n", str(root / "tests" / "order_total.php"), cwd=work, env={},
                    stdin=asyncio.subprocess.PIPE, stdout=asyncio.subprocess.PIPE,
                    stderr=asyncio.subprocess.PIPE, start_new_session=True, limit=MAX_FRAME + 1,
                )

                async def read_error():
                    while chunk := await proc.stderr.read(4096):
                        error_output.extend(chunk)
                        if len(error_output) + len(output) > MAX_OUTPUT:
                            raise FixtureError("Harness output limit exceeded")

                async def handle_calls():
                    nonlocal calls
                    while line := await proc.stdout.readline():
                        if len(line) > MAX_FRAME or not line.endswith(b"\n"):
                            raise FixtureError("Invalid harness frame")
                        if not line.startswith(b"PP_CALL "):
                            output.extend(line)
                            if len(output) + len(error_output) > MAX_OUTPUT:
                                raise FixtureError("Harness output limit exceeded")
                            continue
                        calls += 1
                        if calls > MAX_CALLS:
                            raise FixtureError("Harness call limit exceeded")
                        items = json.loads(line[8:])
                        if not isinstance(items, list):
                            raise FixtureError("Invalid harness input")
                        value = await observe_candidate(source, items, create_sandbox)
                        proc.stdin.write((str(value) + "\n").encode("ascii"))
                        await proc.stdin.drain()

                async with asyncio.TaskGroup() as tasks:
                    tasks.create_task(handle_calls())
                    tasks.create_task(read_error())
                    wait_task = tasks.create_task(proc.wait())
                exit_code = wait_task.result()
                if exit_code == 0 and (calls == 0 or output != b"All order total cases passed.\n" or error_output):
                    raise FixtureError("Harness completion was invalid")
                if exit_code == 0:
                    status = "fixture_passed"
                elif exit_code == 1 and not output and re.fullmatch(
                    rb"(?:(?:empty order|multiple items|zero quantity): expected -?[0-9]+, got -?[0-9]+\n)+",
                    bytes(error_output),
                ):
                    status = "fixture_failed"
                else:
                    raise FixtureError("Harness failed without a valid mismatch")
        except Exception:
            status = "aborted"
        finally:
            if proc is not None:
                if proc.returncode is None:
                    try:
                        os.killpg(proc.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                await proc.wait()
                if proc.stdin is not None:
                    proc.stdin.close()
                    try:
                        await proc.stdin.wait_closed()
                    except (BrokenPipeError, ConnectionResetError):
                        pass

    return {"status": status, "calls": calls, "harness_sha256": HARNESS_SHA256,
            "candidate_sha256": hashlib.sha256(source).hexdigest(),
            "assurance": "experimental finite observations; sandbox and candidate return unverified"}
