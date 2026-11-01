import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import modal_sandbox_runner as runner


class ModalOracleClaimTest(unittest.TestCase):
    def test_success_without_genuine_execution_never_attests(self):
        token = 'a' * 64
        sentinel = f'[PROOFPATCH_ORACLE_SUCCESS:{token}]'
        checks, verified = runner.classify_fixture_result(0, False, False, sentinel, '', token, False)
        self.assertTrue(checks)
        self.assertFalse(verified)

    def test_genuine_success_attests(self):
        token = 'a' * 64
        sentinel = f'[PROOFPATCH_ORACLE_SUCCESS:{token}]'
        self.assertEqual((True, True), runner.classify_fixture_result(0, False, False, sentinel, '', token, True))

    def test_timeout_truncation_and_missing_sentinel_fail_closed(self):
        token = 'b' * 64
        sentinel = f'[PROOFPATCH_ORACLE_SUCCESS:{token}]'
        for args in [(0, True, False, sentinel), (0, False, True, sentinel), (0, False, False, '')]:
            self.assertEqual((False, False), runner.classify_fixture_result(*args, '', token, True))


if __name__ == '__main__':
    unittest.main()
