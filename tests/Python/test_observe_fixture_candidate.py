import base64
import json
import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
import observe_fixture_candidate as observations


async def stream(value):
    if value:
        yield value


class Sandbox:
    def __init__(self, value):
        self.stdout = stream(str(value) + '\n')
        self.stderr = stream('')
        self.returncode = 0
        self.terminated = False

    async def wait(self):
        pass

    async def terminate(self):
        self.terminated = True


class ObserveFixtureCandidateTest(unittest.IsolatedAsyncioTestCase):
    async def test_runs_baseline_negative_and_candidate_with_separate_cases(self):
        sandboxes = []
        async def create(command, options):
            source = base64.b64decode(command[-2])
            items = json.loads(base64.b64decode(command[-1]))
            self.assertTrue(options['block_network'])
            if b'max(1,' in source:
                value = sum(item['unit_price'] * max(1, item['quantity']) for item in items)
            elif b'return 0;' in source:
                value = 0
            else:
                value = sum(item['unit_price'] * item['quantity'] for item in items)
            sb = Sandbox(value)
            sandboxes.append(sb)
            return sb
        result = await observations.observe(b'<?php function candidate() {}', create)
        self.assertEqual(result['status'], 'passed')
        self.assertEqual(result['scope'], 'finite_local_fixture')
        self.assertEqual(result['calls'], {'baseline': 3, 'negative': 3, 'candidate': 3})
        self.assertEqual(len(sandboxes), 9)
        self.assertTrue(all(sb.terminated for sb in sandboxes))

    async def test_candidate_that_fails_is_not_reported_as_passing(self):
        async def create(command, options):
            source = base64.b64decode(command[-2])
            items = json.loads(base64.b64decode(command[-1]))
            value = sum(item['unit_price'] * max(1, item['quantity']) for item in items) if b'max(1,' in source else 0
            return Sandbox(value)
        result = await observations.observe(b'<?php function candidate() {}', create)
        self.assertEqual(result['status'], 'failed')
        self.assertEqual(result['candidate'], 'fixture_failed')

    async def test_empty_candidate_is_rejected_before_sandbox_creation(self):
        async def create(*args):
            self.fail('Sandbox should not be launched')
        with self.assertRaises(ValueError):
            await observations.observe(b'', create)
