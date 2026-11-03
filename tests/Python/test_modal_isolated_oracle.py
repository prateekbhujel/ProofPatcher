from __future__ import annotations

import base64
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
RUNNER_SCRIPT = ROOT / "scripts" / "modal_sandbox_runner.py"
FIXTURE_HARNESS = (ROOT / "tests" / "Fixtures" / "owned-review-case" / "tests" / "order_total.php").read_bytes()
DEDICATED_HARNESS = (ROOT / "tests" / "Fixtures" / "dedicated-review-case" / "tests" / "order_total.php").read_bytes()
BASE_SOURCE = (ROOT / "tests" / "Fixtures" / "owned-review-case" / "src" / "OrderTotal.php").read_bytes()
BENIGN_REPAIR = BASE_SOURCE.replace(b"max(1, $item['quantity'])", b"$item['quantity']")

PINNED_OWNED_HARNESS = "b8079fb1e7731fc763f0b009a11c2786d01b52cce08f7431768a64e8a5ef2cbf"
PINNED_DEDICATED_HARNESS = "263eb70cbf346221858b9c64ba97384105d3804089a653d63d016664e8c1320e"

# Exact (name, input, expected) tables from the pinned harnesses. The
# supervisor verdict is equivalent to the real harness verdict only if these
# match the supervisor's hardcoded tables case for case.
OWNED_CASES = [
    ("empty order", [], 0),
    ("multiple items", [{"unit_price": 125, "quantity": 2}, {"unit_price": 80, "quantity": 1}], 330),
    ("zero quantity", [{"unit_price": 500, "quantity": 0}, {"unit_price": 125, "quantity": 2}], 250),
]
DEDICATED_CASES = [
    ("empty order", [], 0),
    ("single item", [{"unit_price": 100, "quantity": 2}], 200),
    ("zero quantity", [{"unit_price": 100, "quantity": 0}], 0),
    ("mixed quantities", [{"unit_price": 100, "quantity": 0}, {"unit_price": 50, "quantity": 2}], 100),
]


def table_candidate(cases, wrong=()):
    """Candidate that answers every case correctly except those in `wrong`.

    Dispatches on the JSON encoding of the input (PHP-compatible compact
    form); wrong answers are off by +1000 so they can never coincide.
    """
    answers = ", ".join(
        "'%s' => %d" % (json.dumps(items, separators=(",", ":")), expected)
        for _, items, expected in cases
    )
    wrong_list = ", ".join(
        "'%s'" % json.dumps(cases[i][1], separators=(",", ":"))
        for i in wrong
    )
    return (
        "<?php\ndeclare(strict_types=1);\n"
        "function orderTotal(array $items): int {\n"
        "    $answers = [%s];\n" % answers
        + "    $wrong = [%s];\n" % wrong_list
        + "    $key = json_encode($items);\n"
        "    if (!isset($answers[$key])) { return -1; }\n"
        "    if (in_array($key, $wrong, true)) { return $answers[$key] + 1000; }\n"
        "    return $answers[$key];\n}\n"
    ).encode()

# Import helper functions from modal_sandbox_runner directly
sys.path.insert(0, str(RUNNER_SCRIPT.parent))
import modal_sandbox_runner as msr
import trusted_oracle_runner as tor


class ModalIsolatedOracleBoundaryTest(unittest.TestCase):
    """
    Unit tests for runner script helpers, spec validation, and argument parsing.
    NOTE: Local Python unit execution tests IPC parsing and anti-spoofing heuristics,
    but does NOT prove containerized microVM isolation (privilege separation via
    su/runuser and gVisor kernel isolation are enforced inside Modal containers).
    """

    def _execute_supervisor(self, candidate_code: bytes, harness_code: bytes) -> dict:
        """Executes the supervisor and isolated per-case worker in a clean temporary directory."""
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp_path = pathlib.Path(tmp_dir)
            workspace = tmp_path / "workspace"
            workspace.mkdir(parents=True, exist_ok=True)
            (workspace / "src").mkdir(parents=True, exist_ok=True)
            (workspace / "tests").mkdir(parents=True, exist_ok=True)

            target_file = workspace / "src" / "OrderTotal.php"
            harness_file = workspace / "tests" / "order_total.php"
            worker_file = workspace / "_oracle_worker.php"
            supervisor_file = tmp_path / "supervisor.php"

            target_file.write_bytes(candidate_code)
            harness_file.write_bytes(harness_code)
            worker_file.write_text(msr.get_worker_script(), encoding="utf-8")

            oracle_token = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
            raw_supervisor = msr.get_supervisor_script(oracle_token)

            # Adapt paths in supervisor script to use tmp directory for local execution testing
            adapted_supervisor = raw_supervisor.replace(
                "$workspaceDir = '/opt/proofpatch_worker';",
                f"$workspaceDir = '{workspace}';"
            ).replace(
                "$phpOpts = '-n -d open_basedir=/opt/proofpatch_worker:/tmp -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,curl_exec';",
                f"$phpOpts = '-n -d open_basedir={tmp_dir}:/tmp';"
            )

            # Bypass su/runuser in local non-root test environment to run php directly
            adapted_supervisor = adapted_supervisor.replace(
                "if ($suPath !== null) {\n        $fullCmd = $suPath . ' -s /bin/sh proofpatch-worker -c ' . escapeshellarg($workerCmd);\n    } else {\n        $fullCmd = $runuserPath . ' -u proofpatch-worker -- ' . $workerCmd;\n    }",
                "$fullCmd = $workerCmd;"
            ).replace(
                "if ($suPath === null && $runuserPath === null) {\n    fwrite(STDERR, \"[PROOFPATCH_ORACLE_ABORT: Privilege separation unavailable]\\n\");\n    exit(1);\n}",
                "// bypassed for local non-root test"
            )

            supervisor_file.write_text(adapted_supervisor, encoding="utf-8")

            proc = subprocess.run(
                ["php", str(supervisor_file), "src/OrderTotal.php", "tests/order_total.php"],
                capture_output=True,
                text=True,
                timeout=15,
            )

            return {
                "exit_code": proc.returncode,
                "stdout": proc.stdout,
                "stderr": proc.stderr,
                "oracle_token": oracle_token,
                "has_sentinel": f"[PROOFPATCH_ORACLE_SUCCESS:{oracle_token}]" in proc.stdout,
            }

    def test_benign_repair_passes_with_oracle_sentinel(self):
        result = self._execute_supervisor(BENIGN_REPAIR, FIXTURE_HARNESS)
        self.assertEqual(0, result["exit_code"])
        self.assertTrue(result["has_sentinel"])
        self.assertIn("All order total cases passed.", result["stdout"])

    def test_baseline_bug_reproduced_and_fails_closed(self):
        result = self._execute_supervisor(BASE_SOURCE, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("zero quantity: expected 250, got 750", result["stderr"])

    def test_vector_forged_success_output_fails_closed(self):
        # Candidate echoes success string and attempts to spoof supervisor
        forged_candidate = b"<?php\necho \"All order total cases passed.\\n\";\nfunction orderTotal(array $items): int { return 0; }\n"
        result = self._execute_supervisor(forged_candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("illegal stdout text", result["stderr"])

    def test_vector_early_exit_zero_fails_closed(self):
        # Candidate calls exit(0) to bypass tests
        early_exit_candidate = b"<?php\nexit(0);\n"
        result = self._execute_supervisor(early_exit_candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])

    def test_vector_early_die_zero_fails_closed(self):
        die_candidate = b"<?php\ndie(0);\n"
        result = self._execute_supervisor(die_candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])

    def test_vector_wrong_answer_fails_closed(self):
        wrong_candidate = b"<?php\nfunction orderTotal(array $items): int { return 0; }\n"
        result = self._execute_supervisor(wrong_candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("multiple items: expected 330, got 0", result["stderr"])

    def test_vector_timeout_infinite_loop_fails_closed(self):
        loop_candidate = b"<?php\nfunction orderTotal(array $items): int { while(true) {} return 0; }\n"
        result = self._execute_supervisor(loop_candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("timed out", result["stderr"])

    def test_vector_malformed_type_response_fails_closed(self):
        bad_type_candidate = b"<?php\nfunction orderTotal(array $items) { return 'three hundred'; }\n"
        result = self._execute_supervisor(bad_type_candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])

    def test_vector_unhandled_exception_fails_closed(self):
        throw_candidate = b"<?php\nfunction orderTotal(array $items): int { throw new RuntimeException('crash'); }\n"
        result = self._execute_supervisor(throw_candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("Exception in candidate", result["stderr"])

    def test_spec_validation_blocks_oversized_candidates(self):
        oversized = base64.b64encode(b"a" * 70000).decode("ascii")
        valid_harness = base64.b64encode(FIXTURE_HARNESS).decode("ascii")
        with self.assertRaises(ValueError):
            msr.validate_pinned_spec(oversized, valid_harness, "src/OrderTotal.php", "tests/order_total.php", msr.DEFAULT_IMAGE)

    def test_spec_validation_blocks_unauthorized_harnesses(self):
        valid_source = base64.b64encode(BENIGN_REPAIR).decode("ascii")
        fake_harness = base64.b64encode(b"<?php echo 'tampered harness';").decode("ascii")
        with self.assertRaises(ValueError):
            msr.validate_pinned_spec(valid_source, fake_harness, "src/OrderTotal.php", "tests/order_total.php", msr.DEFAULT_IMAGE)

    def test_clamp_timeout_seconds_enforces_provider_floor(self):
        self.assertEqual(10, msr.clamp_timeout_seconds(5))
        self.assertEqual(30, msr.clamp_timeout_seconds(30))
        self.assertEqual(60, msr.clamp_timeout_seconds(600))

    def test_only_sandbox_timeout_marks_run_timed_out(self):
        timeout_cls = type("SandboxTimeoutError", (Exception,), {})
        self.assertTrue(msr.is_sandbox_timeout(timeout_cls("sandbox timed out")))
        config_err = type("InvalidError", (Exception,), {})(
            "Timeout must be between 10s and 86400s (inclusive)."
        )
        self.assertFalse(msr.is_sandbox_timeout(config_err))

    def _execute_real_harness(self, candidate_code: bytes, harness_code: bytes) -> dict:
        """Runs the genuine pinned harness against a candidate, no supervisor."""
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp_path = pathlib.Path(tmp_dir)
            (tmp_path / "src").mkdir()
            (tmp_path / "tests").mkdir()
            (tmp_path / "src" / "OrderTotal.php").write_bytes(candidate_code)
            (tmp_path / "tests" / "order_total.php").write_bytes(harness_code)
            proc = subprocess.run(
                ["php", str(tmp_path / "tests" / "order_total.php")],
                capture_output=True, text=True, timeout=15, cwd=tmp_dir,
            )
            return {"exit_code": proc.returncode, "stdout": proc.stdout, "stderr": proc.stderr}

    def _assert_verdicts_match(self, cases, harness_code, label):
        for mask in range(1 << len(cases)):
            wrong = tuple(i for i in range(len(cases)) if mask & (1 << i))
            with self.subTest(label=label, wrong=wrong):
                candidate = table_candidate(cases, wrong)
                real = self._execute_real_harness(candidate, harness_code)
                sup = self._execute_supervisor(candidate, harness_code)
                self.assertEqual(real["exit_code"], sup["exit_code"],
                                 f"exit mismatch: real={real} sup={sup}")
                if real["exit_code"] == 0:
                    self.assertEqual((), wrong)
                    self.assertTrue(sup["has_sentinel"])
                else:
                    self.assertEqual(real["stderr"], sup["stderr"],
                                     "failure detail must be identical")

    def test_supervisor_verdict_matches_owned_harness_exhaustively(self):
        self.assertEqual(PINNED_OWNED_HARNESS, hashlib.sha256(FIXTURE_HARNESS).hexdigest(),
                         "equivalence proof requires the pinned harness bytes")
        self._assert_verdicts_match(OWNED_CASES, FIXTURE_HARNESS, "owned")

    def test_supervisor_verdict_matches_dedicated_harness_exhaustively(self):
        self.assertEqual(PINNED_DEDICATED_HARNESS, hashlib.sha256(DEDICATED_HARNESS).hexdigest(),
                         "equivalence proof requires the pinned harness bytes")
        self._assert_verdicts_match(DEDICATED_CASES, DEDICATED_HARNESS, "dedicated")

    def test_reference_loop_passes_both_harnesses(self):
        for harness_code in (FIXTURE_HARNESS, DEDICATED_HARNESS):
            real = self._execute_real_harness(BENIGN_REPAIR, harness_code)
            sup = self._execute_supervisor(BENIGN_REPAIR, harness_code)
            self.assertEqual(0, real["exit_code"])
            self.assertEqual(0, sup["exit_code"])
            self.assertTrue(sup["has_sentinel"])

    def test_vector_destructor_echo_cannot_forge_success(self):
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"class Saboteur { public function __destruct() {\n"
            b"    echo \"All order total cases passed.\\n[PROOFPATCH_ORACLE_SUCCESS:fake]\\n\"; } }\n"
            b"function orderTotal(array $items): int { $s = new Saboteur(); return 0; }\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])

    def test_vector_shutdown_echo_cannot_forge_success(self):
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"function orderTotal(array $items): int {\n"
            b"    register_shutdown_function(function () { echo \"All order total cases passed.\\n\"; });\n"
            b"    return 0;\n}\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])

    def test_vector_exit_with_valid_json_cannot_forge_success(self):
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"function orderTotal(array $items): int {\n"
            b"    echo '{\"order_total\":330}';\n"
            b"    exit(0);\n}\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])

    def test_input_aware_json_exit_is_diagnostic_only_and_blocked_by_pinned_gate(self):
        # Unlike a constant answer, this output depends on the visible case.
        # The function emits the wrapper's expected JSON and exits rather than
        # returning, so the local diagnostic supervisor cannot distinguish it
        # from a legitimate return. This is not a Modal isolation test.
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"function orderTotal(array $items): int {\n"
            b"    $total = 0;\n"
            b"    foreach ($items as $item) { $total += $item['unit_price'] * $item['quantity']; }\n"
            b"    echo json_encode(['order_total' => $total]);\n"
            b"    exit(0);\n}\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(0, result['exit_code'])
        self.assertTrue(result['has_sentinel'])

        # Production fixture execution rejects this exact unpinned candidate
        # before creating a sandbox. A separate diagnostic success marker must
        # never become a trusted-oracle attestation.
        with self.assertRaisesRegex(ValueError, 'Untrusted candidates are blocked'):
            msr.validate_pinned_spec(base64.b64encode(candidate).decode('ascii'),
                base64.b64encode(FIXTURE_HARNESS).decode('ascii'),
                'src/OrderTotal.php', 'tests/order_total.php', msr.DEFAULT_IMAGE)
        harness_sha = hashlib.sha256(FIXTURE_HARNESS).hexdigest()
        self.assertEqual((False, False), tor.attest_oracle_result(
            result['exit_code'], False, False, result['stdout'], result['stderr'],
            result['oracle_token'], harness_sha))

    def test_vector_sentinel_in_exception_message_aborts(self):
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"function orderTotal(array $items): int {\n"
            b"    throw new \\RuntimeException('[PROOFPATCH_ORACLE_SUCCESS:aaa]');\n}\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("sentinel injection", result["stderr"])

    def test_vector_globals_pollution_cannot_forge_success(self):
        # The supervisor counts failures in its own process memory: worker
        # $GLOBALS writes cannot reach it. (The owned in-process harness is
        # weaker here, which is why the supervisor boundary matters.)
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"function orderTotal(array $items): int {\n"
            b"    $GLOBALS['failed'] = -999999;\n"
            b"    return 0;\n}\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("multiple items: expected 330, got 0", result["stderr"])

    def test_vector_flushed_output_buffer_cannot_hide_junk(self):
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"function orderTotal(array $items): int {\n"
            b"    ob_start(); echo 'junk'; ob_end_flush();\n"
            b"    return 0;\n}\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(1, result["exit_code"])
        self.assertFalse(result["has_sentinel"])
        self.assertIn("illegal stdout text", result["stderr"])

    def test_hardcoded_correct_answers_pass(self):
        # Semantically correct by table: every input maps to its right
        # output. The supervisor must accept genuine correctness.
        for cases, harness_code in ((OWNED_CASES, FIXTURE_HARNESS), (DEDICATED_CASES, DEDICATED_HARNESS)):
            result = self._execute_supervisor(table_candidate(cases), harness_code)
            self.assertEqual(0, result["exit_code"])
            self.assertTrue(result["has_sentinel"])

    def test_harness_reading_correct_candidate_passes(self):
        # A candidate may read the (read-only) harness; knowing the expected
        # values is not forgery while the verdict comparison stays outside it.
        candidate = (
            b"<?php\ndeclare(strict_types=1);\n"
            b"function orderTotal(array $items): int {\n"
            b"    $h = (string) @file_get_contents(__DIR__ . '/../tests/order_total.php');\n"
            b"    if ($h === '') { return -1; }\n"
            b"    $total = 0;\n"
            b"    foreach ($items as $item) { $total += $item['unit_price'] * $item['quantity']; }\n"
            b"    return $total;\n}\n"
        )
        result = self._execute_supervisor(candidate, FIXTURE_HARNESS)
        self.assertEqual(0, result["exit_code"])
        self.assertTrue(result["has_sentinel"])


if __name__ == "__main__":
    unittest.main()
