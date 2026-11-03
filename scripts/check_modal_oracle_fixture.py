#!/usr/bin/env python3
"""Opt-in, billable Modal smoke test for the experimental pinned fixture.

Run from the repository root with the Modal SDK configured:
  python scripts/check_modal_oracle_fixture.py --execute

Creates up to ten short-lived sandboxes. No credentials are printed or passed
to them. This does not enable the application or prove general isolation.
"""

import argparse
import asyncio
from pathlib import Path

from isolated_fixture_oracle import run_fixture
from modal_oracle_candidate import modal_factory, observe_candidate

ROOT = Path(__file__).resolve().parents[1]
PROBE = b'''<?php function orderTotal(array $items): int {
  foreach (getenv() as $name => $_) {
    if (preg_match('/TOKEN|SECRET|CREDENTIAL|API_KEY/i', $name)) return 10;
  }
  if (is_file('/tests/order_total.php') || is_file('/root/tests/order_total.php')) return 12;
  $s = @fsockopen('1.1.1.1', 80, $code, $message, 1);
  if ($s) { fclose($s); return 13; }
  return 1;
}'''
REPAIR = b'''<?php function orderTotal(array $items): int {
  $total = 0;
  foreach ($items as $item) { $total += $item['unit_price'] * $item['quantity']; }
  return $total;
}'''
NEGATIVE = b'<?php function orderTotal(array $items): int { return 0; }'


async def check():
    import modal

    fixture = ROOT / "tests" / "Fixtures" / "owned-review-case"
    harness = (fixture / "tests" / "order_total.php").read_bytes()
    baseline = (fixture / "src" / "OrderTotal.php").read_bytes()
    app = modal.App("proofpatch-oracle-boundary-probe")
    async with app.run.aio():
        factory = modal_factory(app)
        probe = await observe_candidate(PROBE, [], factory, deadline=55)
        print("boundary_probe", "passed" if probe == 1 else "failed", flush=True)
        if probe != 1:
            return 1
        expected = {"baseline": "fixture_failed", "repair": "fixture_passed", "negative": "fixture_failed"}
        for name, source in (("baseline", baseline), ("repair", REPAIR), ("negative", NEGATIVE)):
            result = await run_fixture(harness, source, factory)
            print(name, result["status"], "sandbox_calls", result["calls"], flush=True)
            if result["status"] != expected[name] or result["calls"] != 3:
                return 1
    return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--execute", action="store_true", help="explicitly authorize billable live Modal calls")
    args = parser.parse_args()
    if not args.execute:
        parser.error("pass --execute to authorize the billable live smoke test")
    return asyncio.run(check())


if __name__ == "__main__":
    raise SystemExit(main())
