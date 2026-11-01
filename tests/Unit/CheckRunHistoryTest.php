<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Evidence\CheckRunHistory;

final class CheckRunHistoryTest extends TestCase
{
    private const RUN_ID = '20261101T103114Z-d959456f5271';

    public function testPrioritizeRequestedFloatsMissingRunToFront(): void
    {
        $root = sys_get_temp_dir().'/proofpatch-prior-'.bin2hex(random_bytes(8));
        mkdir($root.'/'.self::RUN_ID, 0700, true);
        file_put_contents($root.'/'.self::RUN_ID.'/result.json', '{}');
        try {
            $wanted = $root.'/'.self::RUN_ID.'/result.json';
            $files = [$root.'/other/result.json'];

            $prioritized = CheckRunHistory::prioritizeRequested($files, self::RUN_ID, $root);
            self::assertSame([$wanted, $root.'/other/result.json'], $prioritized);

            // Already listed, invalid id, blank id, or missing file: untouched.
            self::assertSame($prioritized, CheckRunHistory::prioritizeRequested($prioritized, self::RUN_ID, $root));
            self::assertSame($files, CheckRunHistory::prioritizeRequested($files, 'bogus', $root));
            self::assertSame($files, CheckRunHistory::prioritizeRequested($files, '', $root));
            self::assertSame($files, CheckRunHistory::prioritizeRequested($files, self::RUN_ID, $root.'/missing'));
        } finally {
            unlink($root.'/'.self::RUN_ID.'/result.json');
            rmdir($root.'/'.self::RUN_ID);
            rmdir($root);
        }
    }

    public function testBaselineReadiness(): void
    {
        $ready = ['integrityValid' => true, 'baseCommit' => 'abc', 'runs' => ['baseline' => ['exitCode' => 1]]];
        self::assertTrue(CheckRunHistory::isBaselineReady($ready, 'abc'));
        self::assertFalse(CheckRunHistory::isBaselineReady(null, 'abc'));
        self::assertFalse(CheckRunHistory::isBaselineReady('junk', 'abc'));
        self::assertFalse(CheckRunHistory::isBaselineReady(array_merge($ready, ['integrityValid' => false]), 'abc'));
        self::assertFalse(CheckRunHistory::isBaselineReady(array_merge($ready, ['baseCommit' => 'def']), 'abc'));
        self::assertFalse(CheckRunHistory::isBaselineReady(
            array_merge($ready, ['runs' => ['baseline' => ['exitCode' => 0]]]), 'abc'
        ));
        self::assertFalse(CheckRunHistory::isBaselineReady([], 'abc'));
    }

    public function testReceiptOutcomeInterpretation(): void
    {
        [$receipt, $error] = CheckRunHistory::receiptOutcome(['valid' => true, 'receipt' => ['sha256' => 'x']]);
        self::assertSame(['sha256' => 'x'], $receipt);
        self::assertNull($error);

        [, $error] = CheckRunHistory::receiptOutcome(['valid' => false, 'reason' => 'stale']);
        self::assertSame('stale', $error);
        [, $error] = CheckRunHistory::receiptOutcome(['valid' => false]);
        self::assertSame('Receipt is invalid.', $error);
        [, $error] = CheckRunHistory::receiptOutcome(null);
        self::assertSame('Receipt is invalid.', $error);
        [, $error] = CheckRunHistory::receiptOutcome(['valid' => true, 'receipt' => 'junk']);
        self::assertSame('Receipt is invalid.', $error);
    }
}
