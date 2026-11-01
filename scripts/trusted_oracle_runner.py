#!/usr/bin/env python3
"""
Experimental oracle transport for ProofPatcher (protocol trusted-oracle/v1).
The CLI is disabled: candidate workers can currently read the pinned harness.
No result from this transport is an oracle attestation or security proof.

The builders below preserve a diagnostic design for future review. Their
network isolation, timeout, cleanup, and trust boundaries are unverified.
Neither CLI nor PHP public entrypoints run this experimental transport.
"""

import argparse
import base64
import binascii
import hashlib
import json
import os
import re
import secrets
import sys
import time

PROTOCOL = "trusted-oracle/v1"
MAX_OUTPUT_BYTES = 65536  # 64 KB backstop; inner per-case caps apply first
MAX_SOURCE_BYTES = 65536
DEFAULT_TIMEOUT_SECONDS = 30
MIN_TIMEOUT_SECONDS = 10  # Modal rejects sandbox timeouts below 10s
MAX_TIMEOUT_SECONDS = 60
DEFAULT_IMAGE = "php@sha256:d716009046f4acac161ed7a14921111398228c2311696f132686be8613cb19ab"

PINNED_HARNESS_HASHES = frozenset({
    "b8079fb1e7731fc763f0b009a11c2786d01b52cce08f7431768a64e8a5ef2cbf",  # owned fixture
    "263eb70cbf346221858b9c64ba97384105d3804089a653d63d016664e8c1320e",  # dedicated repo
})


def clamp_timeout_seconds(value: int) -> int:
    """Clamp to the provider's accepted timeout range (10-60s)."""
    return max(MIN_TIMEOUT_SECONDS, min(MAX_TIMEOUT_SECONDS, value))


def is_sandbox_timeout(exc: BaseException) -> bool:
    """Only Modal's sandbox timeout marks a run timed out.

    Substring matching mislabels config errors such as
    "Timeout must be between 10s and 86400s" as timeouts.
    """
    return type(exc).__name__ == "SandboxTimeoutError"


def validate_relative_path(path: str) -> None:
    if path.startswith("/") or ".." in path or "\\" in path:
        raise ValueError(f"Path traversal or absolute paths are strictly prohibited: '{path}'")
    if not re.match(r"^[a-zA-Z0-9_./-]+$", path):
        raise ValueError(f"Invalid characters in relative file path: '{path}'")


def validate_trusted_spec(
    source_b64: str, harness_b64: str, target: str, harness: str, image: str
) -> str:
    """Validate execution spec; returns the verified harness sha256.

    Any candidate source within the size bound is accepted. The harness
    must be byte-identical to a pinned fixture: it is the oracle.
    """
    if target != "src/OrderTotal.php" or harness != "tests/order_total.php" or image != DEFAULT_IMAGE:
        raise ValueError("Only the pinned fixture paths and runtime image are supported.")
    try:
        source = base64.b64decode(source_b64, validate=True)
        test = base64.b64decode(harness_b64, validate=True)
    except (ValueError, TypeError, binascii.Error) as exc:
        raise ValueError("Invalid fixture encoding.") from exc
    if len(source) < 1 or len(source) > MAX_SOURCE_BYTES:
        raise ValueError("Candidate source exceeds the allowed size.")
    if len(test) < 1 or len(test) > MAX_SOURCE_BYTES:
        raise ValueError("Test harness exceeds the allowed size.")
    harness_sha = hashlib.sha256(test).hexdigest()
    if harness_sha not in PINNED_HARNESS_HASHES:
        raise ValueError("Unrecognized test harness is blocked.")
    return harness_sha


def diagnostic_fixture_success(
    exit_code: int,
    truncated: bool,
    timed_out: bool,
    stdout: str,
    stderr: str,
    token: str,
    harness_sha: str,
) -> bool:
    """Check transport markers only; never an oracle attestation."""
    if harness_sha not in PINNED_HARNESS_HASHES:
        return False
    sentinel = f"[PROOFPATCH_ORACLE_SUCCESS:{token}]"
    passed = (
        exit_code == 0
        and not truncated
        and not timed_out
        and sentinel in stdout
        and "[PROOFPATCH_ORACLE_ABORT" not in stdout
        and "[PROOFPATCH_ORACLE_ABORT" not in stderr
    )
    return passed


def attest_oracle_result(
    exit_code: int, truncated: bool, timed_out: bool, stdout: str, stderr: str,
    token: str, harness_sha: str,
) -> tuple[bool, bool]:
    """Fail closed while candidate code can read oracle cases."""
    return False, False


def get_worker_script() -> str:
    """Worker PHP: runs candidate orderTotal() once, strict JSON integer out."""
    return (
        "<?php\n"
        "declare(strict_types=1);\n"
        "ini_set('display_errors', '0');\n"
        "error_reporting(E_ALL);\n"
        "$candidatePath = $argv[1] ?? '';\n"
        "$inputJson = $argv[2] ?? '';\n"
        "if (!is_file($candidatePath)) {\n"
        "    fwrite(STDERR, \"Candidate file missing.\\n\");\n"
        "    exit(2);\n"
        "}\n"
        "$items = json_decode($inputJson, true);\n"
        "if (!is_array($items)) {\n"
        "    fwrite(STDERR, \"Invalid input JSON.\\n\");\n"
        "    exit(2);\n"
        "}\n"
        "ob_start();\n"
        "try {\n"
        "    require $candidatePath;\n"
        "    if (!function_exists('orderTotal')) {\n"
        "        fwrite(STDERR, \"Function orderTotal() not defined in candidate.\\n\");\n"
        "        exit(2);\n"
        "    }\n"
        "    $result = orderTotal($items);\n"
        "} catch (\\Throwable $e) {\n"
        "    ob_end_clean();\n"
        "    fwrite(STDERR, \"Exception in candidate: \" . $e->getMessage() . \"\\n\");\n"
        "    exit(3);\n"
        "}\n"
        "$spuriousOutput = ob_get_clean();\n"
        "if ($spuriousOutput !== '' && $spuriousOutput !== false) {\n"
        "    fwrite(STDERR, \"Candidate emitted illegal stdout text: \" . substr((string) $spuriousOutput, 0, 100) . \"\\n\");\n"
        "    exit(4);\n"
        "}\n"
        "if (!is_int($result)) {\n"
        "    fwrite(STDERR, \"Candidate returned non-integer value.\\n\");\n"
        "    exit(5);\n"
        "}\n"
        "echo json_encode(['order_total' => $result], JSON_THROW_ON_ERROR);\n"
        "exit(0);\n"
    )


def get_case_parser_php() -> str:
    """Standalone parser: expected cases from harness bytes, data-only tokens.

    Returned separately so offline tests can execute it against fixture
    harnesses without a sandbox. The supervisor embeds this verbatim.
    """
    return (
        "function proofpatch_extract_cases(string $harnessContent): array {\n"
        "    $tokens = token_get_all($harnessContent);\n"
        "    $count = count($tokens);\n"
        "    $pos = null;\n"
        "    for ($i = 0; $i < $count; $i++) {\n"
        "        $token = $tokens[$i];\n"
        "        if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$cases') { $pos = $i; break; }\n"
        "    }\n"
        "    if ($pos === null) { throw new \\RuntimeException('Harness case table not found.'); }\n"
        "    $i = $pos + 1;\n"
        "    $skipTrivia = static function () use ($tokens, $count, &$i): void {\n"
        "        while ($i < $count) {\n"
        "            $token = $tokens[$i];\n"
        "            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $i++; continue; }\n"
        "            break;\n"
        "        }\n"
        "    };\n"
        "    $skipTrivia();\n"
        "    if ($i >= $count || $tokens[$i] !== '=') { throw new \\RuntimeException('Harness case table is malformed.'); }\n"
        "    $i++;\n"
        "    $skipTrivia();\n"
        "    if ($i >= $count || $tokens[$i] !== '[') { throw new \\RuntimeException('Harness case table is malformed.'); }\n"
        "    $literal = '';\n"
        "    $depth = 0;\n"
        "    for (; $i < $count; $i++) {\n"
        "        $token = $tokens[$i];\n"
        "        if (is_string($token)) {\n"
        "            if (!in_array($token, ['[', ']', ',', '-'], true)) { throw new \\RuntimeException('Harness case table uses disallowed syntax.'); }\n"
        "            if ($token === '[') { $depth++; }\n"
        "            if ($token === ']') { $depth--; }\n"
        "            $literal .= $token;\n"
        "            if ($depth === 0) { $i++; break; }\n"
        "            continue;\n"
        "        }\n"
        "        [$id, $text] = $token;\n"
        "        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_ARROW], true)) {\n"
        "            $literal .= $text;\n"
        "            continue;\n"
        "        }\n"
        "        throw new \\RuntimeException('Harness case table uses disallowed syntax.');\n"
        "    }\n"
        "    if ($depth !== 0 || $literal === '') { throw new \\RuntimeException('Harness case table is malformed.'); }\n"
        "    $skipTrivia();\n"
        "    if ($i >= $count || $tokens[$i] !== ';') { throw new \\RuntimeException('Harness case table is malformed.'); }\n"
        "    $cases = eval('return ' . $literal . ';');\n"
        "    if (!is_array($cases) || $cases === [] || count($cases) > 32) { throw new \\RuntimeException('Harness case table is empty or oversized.'); }\n"
        "    foreach ($cases as $name => $pair) {\n"
        "        if (!is_string($name) || $name === '' || strlen($name) > 128) { throw new \\RuntimeException('Harness case name is invalid.'); }\n"
        "        if (!is_array($pair) || array_keys($pair) !== [0, 1]) { throw new \\RuntimeException(\"Harness case '{$name}' is malformed.\"); }\n"
        "        [$items, $expected] = $pair;\n"
        "        if (!is_array($items) || count($items) > 64 || !is_int($expected) || abs($expected) > 1000000000) {\n"
        "            throw new \\RuntimeException(\"Harness case '{$name}' is malformed.\");\n"
        "        }\n"
        "        foreach ($items as $item) {\n"
        "            if (!is_array($item) || !is_int($item['unit_price'] ?? null) || !is_int($item['quantity'] ?? null)\n"
        "                || abs($item['unit_price']) > 1000000000 || abs($item['quantity']) > 1000000000) {\n"
        "                throw new \\RuntimeException(\"Harness case '{$name}' has invalid items.\");\n"
        "            }\n"
        "        }\n"
        "    }\n"
        "    return $cases;\n"
        "}\n"
    )


def get_supervisor_script(oracle_token: str) -> str:
    """Root supervisor: parses oracle cases, runs isolated workers, attests."""
    pinned = ", ".join(f"'{h}'" for h in sorted(PINNED_HARNESS_HASHES))
    return (
        "<?php\n"
        "declare(strict_types=1);\n"
        + get_case_parser_php()
        + "$workspaceDir = '/opt/proofpatch_worker';\n"
        "$targetRel = $argv[1] ?? 'src/OrderTotal.php';\n"
        "$harnessRel = $argv[2] ?? 'tests/order_total.php';\n"
        f"$oracleToken = '{oracle_token}';\n"
        f"$pinnedHarness = [{pinned}];\n"
        "$targetFile = $workspaceDir . '/' . $targetRel;\n"
        "$harnessFile = $workspaceDir . '/' . $harnessRel;\n"
        "$workerScript = $workspaceDir . '/_oracle_worker.php';\n"
        "if (!is_file($targetFile) || !is_file($harnessFile) || !is_file($workerScript)) {\n"
        "    fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Required files missing in workspace]\\n\");\n"
        "    exit(1);\n"
        "}\n"
        "$initialTargetSha = hash_file('sha256', $targetFile);\n"
        "$initialHarnessSha = hash_file('sha256', $harnessFile);\n"
        "$initialWorkerSha = hash_file('sha256', $workerScript);\n"
        "if (!in_array($initialHarnessSha, $pinnedHarness, true)) {\n"
        "    fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Unrecognized test harness]\\n\");\n"
        "    exit(1);\n"
        "}\n"
        "try {\n"
        "    $cases = proofpatch_extract_cases((string) file_get_contents($harnessFile));\n"
        "} catch (\\Throwable $e) {\n"
        "    fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: \" . $e->getMessage() . \"]\\n\");\n"
        "    exit(1);\n"
        "}\n"
        "$suPath = is_executable('/bin/su') ? '/bin/su' : (is_executable('/usr/bin/su') ? '/usr/bin/su' : null);\n"
        "$runuserPath = is_executable('/sbin/runuser') ? '/sbin/runuser' : (is_executable('/usr/sbin/runuser') ? '/usr/sbin/runuser' : null);\n"
        "if ($suPath === null && $runuserPath === null) {\n"
        "    fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Privilege separation unavailable]\\n\");\n"
        "    exit(1);\n"
        "}\n"
        "$phpOpts = '-n -d open_basedir=/opt/proofpatch_worker'\n"
        "    . ' -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_init,'\n"
        "    . 'pcntl_fork,pcntl_exec,pcntl_wait,pcntl_waitpid,pcntl_signal,pcntl_alarm,'\n"
        "    . 'socket_create,socket_connect,fsockopen,pfsockopen,stream_socket_client,symlink,link';\n"
        "$failures = [];\n"
        "$totalCases = count($cases);\n"
        "$passedCases = 0;\n"
        "$caseCap = 16384;\n"
        "foreach ($cases as $name => [$items, $expected]) {\n"
        "    $itemsJson = json_encode($items, JSON_THROW_ON_ERROR);\n"
        "    $workerCmd = sprintf(\n"
        "        'php %s %s %s %s',\n"
        "        $phpOpts,\n"
        "        escapeshellarg($workerScript),\n"
        "        escapeshellarg($targetFile),\n"
        "        escapeshellarg($itemsJson)\n"
        "    );\n"
        "    if ($suPath !== null) {\n"
        "        $fullCmd = $suPath . ' -s /bin/sh proofpatch-worker -c ' . escapeshellarg($workerCmd);\n"
        "    } else {\n"
        "        $fullCmd = $runuserPath . ' -u proofpatch-worker -- ' . $workerCmd;\n"
        "    }\n"
        "    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];\n"
        "    $proc = proc_open($fullCmd, $descriptors, $pipes, $workspaceDir);\n"
        "    if (!is_resource($proc)) {\n"
        "        fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Failed to spawn worker for case '{$name}']\\n\");\n"
        "        exit(1);\n"
        "    }\n"
        "    fclose($pipes[0]);\n"
        "    $stdout = '';\n"
        "    $stderr = '';\n"
        "    $deadline = microtime(true) + 5.0;\n"
        "    $caseTimedOut = false;\n"
        "    $caseFlooded = false;\n"
        "    stream_set_blocking($pipes[1], false);\n"
        "    stream_set_blocking($pipes[2], false);\n"
        "    while (is_resource($proc)) {\n"
        "        $pstatus = proc_get_status($proc);\n"
        "        if (!$pstatus['running']) {\n"
        "            $stdout .= (string) stream_get_contents($pipes[1], $caseCap - strlen($stdout) + 1);\n"
        "            $stderr .= (string) stream_get_contents($pipes[2], $caseCap - strlen($stderr) + 1);\n"
        "            break;\n"
        "        }\n"
        "        if (microtime(true) > $deadline) {\n"
        "            proc_terminate($proc, 9);\n"
        "            $caseTimedOut = true;\n"
        "            $stderr .= \"case '{$name}' timed out after 5s\\n\";\n"
        "            break;\n"
        "        }\n"
        "        $read = [$pipes[1], $pipes[2]];\n"
        "        $w = null; $e = null;\n"
        "        if (@stream_select($read, $w, $e, 0, 50000) > 0) {\n"
        "            foreach ($read as $stream) {\n"
        "                $chunk = fread($stream, 4096);\n"
        "                if ($chunk !== false && $chunk !== '') {\n"
        "                    if ($stream === $pipes[1]) { $stdout .= $chunk; } else { $stderr .= $chunk; }\n"
        "                }\n"
        "            }\n"
        "        }\n"
        "        if (strlen($stdout) > $caseCap || strlen($stderr) > $caseCap) {\n"
        "            proc_terminate($proc, 9);\n"
        "            $caseFlooded = true;\n"
        "            break;\n"
        "        }\n"
        "    }\n"
        "    fclose($pipes[1]);\n"
        "    fclose($pipes[2]);\n"
        "    $exitCode = proc_close($proc);\n"
        "    if ($caseFlooded || strlen($stdout) > $caseCap || strlen($stderr) > $caseCap) {\n"
        "        fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Worker output exceeded its bound]\\n\");\n"
        "        exit(1);\n"
        "    }\n"
        "    if ($caseTimedOut) {\n"
        "        $failures[] = \"{$name}: execution timed out\";\n"
        "        break;\n"
        "    }\n"
        "    if (str_contains($stdout, '[PROOFPATCH_ORACLE_SUCCESS') || str_contains($stderr, '[PROOFPATCH_ORACLE_SUCCESS')\n"
        "        || str_contains($stdout, '[PROOFPATCH_ORACLE_ABORT') || str_contains($stderr, '[PROOFPATCH_ORACLE_ABORT')) {\n"
        "        fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Candidate attempted oracle sentinel injection]\\n\");\n"
        "        exit(1);\n"
        "    }\n"
        "    if ($exitCode !== 0 || $stderr !== '') {\n"
        "        $errSummary = trim($stderr) !== '' ? trim($stderr) : \"worker exited with code {$exitCode}\";\n"
        "        $failures[] = \"{$name}: {$errSummary}\";\n"
        "        continue;\n"
        "    }\n"
        "    $decoded = json_decode(trim($stdout), true);\n"
        "    if (!is_array($decoded) || !array_key_exists('order_total', $decoded) || !is_int($decoded['order_total'])) {\n"
        "        $failures[] = \"{$name}: invalid worker output structure\";\n"
        "        continue;\n"
        "    }\n"
        "    $actual = $decoded['order_total'];\n"
        "    if ($actual !== $expected) {\n"
        "        $failures[] = \"{$name}: expected {$expected}, got {$actual}\";\n"
        "    } else {\n"
        "        $passedCases++;\n"
        "    }\n"
        "}\n"
        "$postTargetSha = hash_file('sha256', $targetFile);\n"
        "$postHarnessSha = hash_file('sha256', $harnessFile);\n"
        "$postWorkerSha = hash_file('sha256', $workerScript);\n"
        "if (!hash_equals($initialTargetSha, $postTargetSha) || !hash_equals($initialHarnessSha, $postHarnessSha)\n"
        "    || !hash_equals($initialWorkerSha, $postWorkerSha)) {\n"
        "    fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Filesystem tampering detected during harness execution]\\n\");\n"
        "    exit(1);\n"
        "}\n"
        "if ($failures !== []) {\n"
        "    foreach ($failures as $f) {\n"
        "        fwrite(STDERR, $f . \"\\n\");\n"
        "    }\n"
        "    exit(1);\n"
        "}\n"
        "if ($passedCases !== $totalCases) {\n"
        "    fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Test harness did not reach natural completion]\\n\");\n"
        "    exit(1);\n"
        "}\n"
        "echo \"All order total cases passed.\\n\";\n"
        "echo \"\\n[PROOFPATCH_ORACLE_SUCCESS:{$oracleToken}]\\n\";\n"
        "exit(0);\n"
    )


def _run_diagnostic_transport() -> int:
    parser = argparse.ArgumentParser(description="ProofPatch trusted oracle runner (Modal microVM)")
    parser.add_argument("spec_file", help="Path to JSON file with runner execution spec")
    parser.add_argument("result_file", help="Path to host private result JSON output file")
    args = parser.parse_args()

    if not os.path.isfile(args.spec_file):
        sys.stderr.write(f"Spec file not found: {args.spec_file}\n")
        return 2

    try:
        with open(args.spec_file, "r", encoding="utf-8") as f:
            spec = json.load(f)
    except Exception as e:
        sys.stderr.write(f"Failed to read or parse spec file: {e}\n")
        return 2

    source_b64 = spec.get("source_b64")
    test_harness_b64 = spec.get("test_harness_b64")
    relative_path = spec.get("relative_path", "src/OrderTotal.php")
    harness_path = spec.get("test_harness_path", "tests/order_total.php")
    timeout_seconds = int(spec.get("timeout_seconds", DEFAULT_TIMEOUT_SECONDS))
    image_tag = spec.get("image", DEFAULT_IMAGE)
    oracle_token = spec.get("oracle_token") or secrets.token_hex(32)

    if not isinstance(oracle_token, str) or not re.fullmatch(r"[a-f0-9]{64}", oracle_token):
        sys.stderr.write("Validation error: Invalid oracle token.\n")
        return 2

    if not source_b64 or not test_harness_b64:
        sys.stderr.write("Spec must contain non-empty 'source_b64' and 'test_harness_b64'.\n")
        return 2

    try:
        validate_relative_path(relative_path)
        validate_relative_path(harness_path)
        harness_sha = validate_trusted_spec(source_b64, test_harness_b64, relative_path, harness_path, image_tag)
    except ValueError as ve:
        sys.stderr.write(f"Validation error: {ve}\n")
        return 2

    timeout_seconds = clamp_timeout_seconds(timeout_seconds)

    worker_php = get_worker_script()
    worker_b64 = base64.b64encode(worker_php.encode("utf-8")).decode("ascii")

    supervisor_php = get_supervisor_script(oracle_token)
    supervisor_b64 = base64.b64encode(supervisor_php.encode("utf-8")).decode("ascii")

    try:
        import modal
    except ImportError:
        sys.stderr.write("Modal SDK not installed in current Python environment.\n")
        return 3

    entrypoint = (
        "id -u proofpatch-worker >/dev/null 2>&1 || (useradd -m -s /bin/bash proofpatch-worker 2>/dev/null || adduser -D proofpatch-worker) && "
        f"mkdir -p /root/supervisor /opt/proofpatch_worker/$(dirname '{relative_path}') /opt/proofpatch_worker/$(dirname '{harness_path}') && "
        "chmod 0700 /root /root/supervisor && "
        f"echo '{supervisor_b64}' | base64 -d > /root/supervisor/proofpatch_supervisor.php && "
        "chmod 0700 /root/supervisor/proofpatch_supervisor.php && "
        f"echo '{worker_b64}' | base64 -d > /opt/proofpatch_worker/_oracle_worker.php && "
        f"echo '{test_harness_b64}' | base64 -d > '/opt/proofpatch_worker/{harness_path}' && "
        f"echo '{source_b64}' | base64 -d > '/opt/proofpatch_worker/{relative_path}' && "
        "chown -R root:root /opt/proofpatch_worker && "
        "find /opt/proofpatch_worker -type d -exec chmod 0555 {} + && "
        "find /opt/proofpatch_worker -type f -exec chmod 0444 {} + && "
        f"php /root/supervisor/proofpatch_supervisor.php '{relative_path}' '{harness_path}'"
    )

    app = modal.App("proofpatch-trusted-oracle")
    image = modal.Image.from_registry(image_tag, add_python="3.11")

    sb = None
    exit_code: int | None = None
    stdout_data = ""
    stderr_data = ""
    sandbox_id = "unknown"
    timed_out = False

    try:
        with app.run():
            try:
                sb = modal.Sandbox.create(
                    "bash", "-c", entrypoint,
                    app=app,
                    image=image,
                    runtime="gvisor",
                    block_network=True,
                    timeout=timeout_seconds,
                    cpu=(0.25, 0.25),
                    memory=(256, 256),
                    secrets=[],
                    volumes={},
                    env={},
                )
                sandbox_id = getattr(sb, "object_id", "unknown")

                deadline = time.monotonic() + timeout_seconds + 120
                while exit_code is None:
                    try:
                        exit_code = sb.poll()
                    except Exception as poll_err:
                        if is_sandbox_timeout(poll_err):
                            timed_out = True
                            stderr_data += f"\nSandbox wait timed out: {poll_err}\n"
                        else:
                            stderr_data += f"\nSandbox poll error: {poll_err}\n"
                        exit_code = 124 if timed_out else 1
                        break
                    if exit_code is None:
                        if time.monotonic() > deadline:
                            timed_out = True
                            stderr_data += "\n[TIMEOUT: Sandbox exceeded its host deadline.]\n"
                            exit_code = 124
                            break
                        time.sleep(0.5)

                try:
                    stdout_data = sb.stdout.read()
                except Exception as read_err:
                    stderr_data += f"\nStdout read error: {read_err}\n"
                try:
                    stderr_data += sb.stderr.read()
                except Exception as read_err:
                    stderr_data += f"\nStderr read error: {read_err}\n"

            except Exception as run_err:
                stderr_data += f"\nSandbox execution error: {run_err}\n"
                if is_sandbox_timeout(run_err):
                    exit_code = 124
                    timed_out = True
                else:
                    exit_code = 1

    except Exception as app_err:
        stderr_data += f"\nModal app error: {app_err}\n"
        exit_code = 1
    finally:
        if sb is not None:
            try:
                sb.terminate(wait=True)
            except Exception:
                pass

    if exit_code is None:
        exit_code = 1

    combined_len = len(stdout_data) + len(stderr_data)
    truncated = False
    if combined_len > MAX_OUTPUT_BYTES:
        truncated = True
        remaining = max(0, MAX_OUTPUT_BYTES - len(stderr_data))
        stdout_data = stdout_data[:remaining]
        exit_code = 1

    fixture_checks_passed, attested = attest_oracle_result(
        exit_code, truncated, timed_out, stdout_data, stderr_data, oracle_token, harness_sha
    )
    if not attested and exit_code == 0:
        exit_code = 1

    result_data = {
        "protocol": PROTOCOL,
        "sandbox_id": sandbox_id,
        "exit_code": exit_code,
        "attested": attested,
        "fixture_checks_passed": fixture_checks_passed,
        "harness_sha256": harness_sha,
        "stdout": stdout_data,
        "stderr": stderr_data,
        "network_blocked": True,
        "runtime": "gvisor",
        "credentials_mounted": False,
        "volumes_mounted": False,
        "timed_out": timed_out,
        "truncated": truncated,
    }

    try:
        with open(args.result_file, "w", encoding="utf-8") as rf:
            json.dump(result_data, rf, indent=2)
            rf.write("\n")
    except Exception as io_err:
        sys.stderr.write(f"Failed to write private result file: {io_err}\n")
        return 1

    sys.stdout.write(stdout_data)
    sys.stderr.write(stderr_data)
    return exit_code


def main() -> int:
    sys.stderr.write("Trusted oracle disabled: candidate access to the test oracle is not isolated.\n")
    return 3


if __name__ == "__main__":
    sys.exit(main())
