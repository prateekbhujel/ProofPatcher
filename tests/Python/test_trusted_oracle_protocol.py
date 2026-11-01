import base64
import hashlib
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import trusted_oracle_runner as runner

OWNED_HARNESS = (ROOT / 'tests/Fixtures/owned-review-case/tests/order_total.php').read_text()
OWNED_SOURCE = (ROOT / 'tests/Fixtures/owned-review-case/src/OrderTotal.php').read_text()

EXPECTED_OWNED_CASES = {
    'empty order': [[], 0],
    'multiple items': [[{'unit_price': 125, 'quantity': 2}, {'unit_price': 80, 'quantity': 1}], 330],
    'zero quantity': [[{'unit_price': 500, 'quantity': 0}, {'unit_price': 125, 'quantity': 2}], 250],
}


def b64(text: str) -> str:
    return base64.b64encode(text.encode()).decode()


class TrustedSpecTest(unittest.TestCase):
    def test_arbitrary_source_accepted_with_pinned_harness(self):
        harness_sha = runner.validate_trusted_spec(
            b64('<?php // arbitrary candidate'),
            b64(OWNED_HARNESS),
            'src/OrderTotal.php', 'tests/order_total.php', runner.DEFAULT_IMAGE,
        )
        self.assertEqual(hashlib.sha256(OWNED_HARNESS.encode()).hexdigest(), harness_sha)
        self.assertIn(harness_sha, runner.PINNED_HARNESS_HASHES)

    def test_unknown_harness_blocked(self):
        with self.assertRaises(ValueError):
            runner.validate_trusted_spec(
                b64('<?php'), b64('<?php // forged harness'),
                'src/OrderTotal.php', 'tests/order_total.php', runner.DEFAULT_IMAGE,
            )

    def test_wrong_paths_image_and_sizes_blocked(self):
        good_harness = b64(OWNED_HARNESS)
        with self.assertRaises(ValueError):
            runner.validate_trusted_spec(b64('x'), good_harness, 'src/Other.php', 'tests/order_total.php', runner.DEFAULT_IMAGE)
        with self.assertRaises(ValueError):
            runner.validate_trusted_spec(b64('x'), good_harness, 'src/OrderTotal.php', 'tests/other.php', runner.DEFAULT_IMAGE)
        with self.assertRaises(ValueError):
            runner.validate_trusted_spec(b64('x'), good_harness, 'src/OrderTotal.php', 'tests/order_total.php', 'php:latest')
        with self.assertRaises(ValueError):
            runner.validate_trusted_spec(b64('x' * (runner.MAX_SOURCE_BYTES + 1)), good_harness,
                                          'src/OrderTotal.php', 'tests/order_total.php', runner.DEFAULT_IMAGE)
        with self.assertRaises(ValueError):
            runner.validate_trusted_spec('!!!not-base64!!!', good_harness,
                                          'src/OrderTotal.php', 'tests/order_total.php', runner.DEFAULT_IMAGE)
        for bad in ['/abs/path.php', '../escape.php', 'a\\b.php', 'a;b.php']:
            with self.assertRaises(ValueError, msg=bad):
                runner.validate_relative_path(bad)


class AttestationTest(unittest.TestCase):
    def test_success_marker_is_diagnostic_only_and_never_attests(self):
        token = 'c' * 64
        harness_sha = hashlib.sha256(OWNED_HARNESS.encode()).hexdigest()
        sentinel = f'[PROOFPATCH_ORACLE_SUCCESS:{token}]'
        self.assertTrue(runner.diagnostic_fixture_success(0, False, False, sentinel, '', token, harness_sha))
        self.assertEqual((False, False), runner.attest_oracle_result(0, False, False, sentinel, '', token, harness_sha))

    def test_fail_closed_cases(self):
        token = 'd' * 64
        harness_sha = hashlib.sha256(OWNED_HARNESS.encode()).hexdigest()
        sentinel = f'[PROOFPATCH_ORACLE_SUCCESS:{token}]'
        cases = [
            (1, False, False, sentinel, ''),           # nonzero exit
            (0, True, False, sentinel, ''),            # truncated
            (0, False, True, sentinel, ''),            # timed out
            (0, False, False, '', ''),                 # missing sentinel
            (0, False, False, sentinel, '[PROOFPATCH_ORACLE_ABORT: x]'),  # abort in stderr
            (0, False, False, sentinel + '[PROOFPATCH_ORACLE_ABORT: x]', ''),  # abort in stdout
            (0, False, False, '[PROOFPATCH_ORACLE_SUCCESS:wrong]', ''),  # wrong token
        ]
        for args in cases:
            with self.subTest(args=args):
                self.assertFalse(runner.diagnostic_fixture_success(*args, token, harness_sha))
                self.assertEqual((False, False), runner.attest_oracle_result(*args, token, harness_sha))
        # Unpinned harness never attests, even with a sentinel.
        self.assertEqual((False, False), runner.attest_oracle_result(0, False, False, sentinel, '', token, '0' * 64))

    def test_cli_is_disabled(self):
        self.assertEqual(3, runner.main())


class SupervisorProtocolTest(unittest.TestCase):
    def test_worker_knows_no_oracle_secrets(self):
        worker = runner.get_worker_script()
        self.assertNotIn('PROOFPATCH_ORACLE_SUCCESS', worker)
        self.assertNotIn('oracle_token', worker)
        self.assertIn('orderTotal', worker)
        self.assertIn('order_total', worker)

    def test_supervisor_parses_cases_enforces_identity_and_caps(self):
        supervisor = runner.get_supervisor_script('e' * 64)
        self.assertIn('proofpatch_extract_cases', supervisor)
        self.assertIn('Unrecognized test harness', supervisor)
        self.assertIn('Privilege separation unavailable', supervisor)
        self.assertIn('sentinel injection', supervisor)
        self.assertIn('output exceeded its bound', supervisor)
        self.assertIn('Filesystem tampering', supervisor)
        self.assertIn('did not reach natural completion', supervisor)
        self.assertIn('proofpatch-worker', supervisor)
        self.assertIn('pcntl_fork', supervisor)
        for pinned in runner.PINNED_HARNESS_HASHES:
            self.assertIn(pinned, supervisor)

    def test_generated_php_parses(self):
        for name, code in [('worker', runner.get_worker_script()),
                           ('supervisor', runner.get_supervisor_script('f' * 64))]:
            with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False) as tmp:
                tmp.write(code)
                path = tmp.name
            try:
                proc = subprocess.run(['php', '-l', path], capture_output=True, text=True, timeout=15)
            finally:
                pathlib.Path(path).unlink(missing_ok=True)
            self.assertEqual(0, proc.returncode, f'{name}: {proc.stderr}')


class CaseParserExecutionTest(unittest.TestCase):
    def _run_parser(self, harness: str) -> dict:
        driver = (
            "<?php\ndeclare(strict_types=1);\n"
            + runner.get_case_parser_php()
            + "$harness = file_get_contents($argv[1]);\n"
            + "try {\n"
            + "  $cases = proofpatch_extract_cases($harness);\n"
            + "  echo json_encode(['ok' => true, 'cases' => $cases], JSON_THROW_ON_ERROR);\n"
            + "} catch (\\Throwable $e) {\n"
            + "  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);\n"
            + "}\n"
        )
        with tempfile.TemporaryDirectory() as tmp:
            driver_path = pathlib.Path(tmp) / 'driver.php'
            harness_path = pathlib.Path(tmp) / 'harness.php'
            driver_path.write_text(driver)
            harness_path.write_text(harness)
            proc = subprocess.run(['php', str(driver_path), str(harness_path)],
                                  capture_output=True, text=True, timeout=15)
        self.assertEqual(0, proc.returncode, proc.stderr)
        return json.loads(proc.stdout)

    def test_owned_fixture_cases_match_ground_truth(self):
        result = self._run_parser(OWNED_HARNESS)
        self.assertTrue(result['ok'], result.get('error'))
        cases = result['cases']
        self.assertEqual(set(EXPECTED_OWNED_CASES), set(cases))
        for name, (items, expected) in EXPECTED_OWNED_CASES.items():
            self.assertEqual(items, cases[name][0], name)
            self.assertEqual(expected, cases[name][1], name)

    def test_rejects_code_injection_and_malformed_tables(self):
        evil = "<?php\n$cases = [str_repeat('x', 3) => [[], 0]];\n"
        self.assertFalse(self._run_parser(evil)['ok'])
        evil_assign = "<?php\n$cases = $_GET['x'];\n"
        self.assertFalse(self._run_parser(evil_assign)['ok'])
        self.assertFalse(self._run_parser("<?php\n// no table here\n")['ok'])
        self.assertFalse(self._run_parser("<?php\n$cases = [];\n")['ok'])
        wrong_shape = "<?php\n$cases = ['x' => [[], 'not-an-int']];\n"
        self.assertFalse(self._run_parser(wrong_shape)['ok'])
        bad_item = "<?php\n$cases = ['x' => [[['unit_price' => 1]], 0]];\n"
        self.assertFalse(self._run_parser(bad_item)['ok'])

    def test_rejects_interpolated_and_oversized_tables(self):
        interp = '<?php\n$name = "x";\n$cases = ["$name" => [[], 0]];\n'
        self.assertFalse(self._run_parser(interp)['ok'])
        big = "<?php\n$cases = [" + ", ".join(f"'c{i}' => [[], {i}]" for i in range(40)) + "];\n"
        result = self._run_parser(big)
        self.assertFalse(result['ok'])
        self.assertIn('oversized', result['error'])


class TrustedTimeoutFloorTest(unittest.TestCase):
    def test_clamp_timeout_seconds_enforces_provider_floor(self):
        self.assertEqual(10, runner.clamp_timeout_seconds(5))
        self.assertEqual(30, runner.clamp_timeout_seconds(30))
        self.assertEqual(60, runner.clamp_timeout_seconds(600))

    def test_only_sandbox_timeout_marks_run_timed_out(self):
        timeout_cls = type("SandboxTimeoutError", (Exception,), {})
        self.assertTrue(runner.is_sandbox_timeout(timeout_cls("sandbox timed out")))
        config_err = type("InvalidError", (Exception,), {})(
            "Timeout must be between 10s and 86400s (inclusive)."
        )
        self.assertFalse(runner.is_sandbox_timeout(config_err))


if __name__ == '__main__':
    unittest.main()
