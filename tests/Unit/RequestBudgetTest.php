<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\RequestBudget;

final class RequestBudgetTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/proofpatch-budget-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_file($this->directory.'/runtime-ledger.json')) {
            unlink($this->directory.'/runtime-ledger.json');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testReservationsSurviveFailureAndAreMeteredNotBlocked(): void
    {
        $budget = new RequestBudget($this->directory, 50000, 3);
        $calls = 0;
        $budget->run(20000, function () use (&$calls): array { ++$calls; return ['usage' => []]; });
        try {
            $budget->run(20000, function () use (&$calls): array { ++$calls; throw new \RuntimeException('Timeout'); });
            self::fail('A failed request was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Timeout', $error->getMessage());
        }
        $status = (new RequestBudget($this->directory, 50000, 3))->status();
        self::assertSame(2, $status['attempts']);
        self::assertSame(40000, $status['reservedMicros']);
        // Dollar reservations never block (metering only)...
        $budget->run(15000, function () use (&$calls): array { ++$calls; return ['usage' => []]; });
        self::assertSame(3, $calls);
        // ...but an explicitly configured attempt cap still applies.
        $this->expectException(\RuntimeException::class);
        $budget->run(1000, function () use (&$calls): array { ++$calls; return ['usage' => []]; });
    }

    public function testDefaultLimitsAndFactoryMethod(): void
    {
        $previous = getenv('PROOFPATCH_MODEL_MAX_ATTEMPTS');
        $prevEnv = $_ENV['PROOFPATCH_MODEL_MAX_ATTEMPTS'] ?? null;
        $prevServer = $_SERVER['PROOFPATCH_MODEL_MAX_ATTEMPTS'] ?? null;
        putenv('PROOFPATCH_MODEL_MAX_ATTEMPTS');
        unset($_ENV['PROOFPATCH_MODEL_MAX_ATTEMPTS'], $_SERVER['PROOFPATCH_MODEL_MAX_ATTEMPTS']);
        try {
            self::assertSame(PHP_INT_MAX, RequestBudget::DEFAULT_LIMIT_MICROS);
            self::assertSame(PHP_INT_MAX, RequestBudget::DEFAULT_MAX_ATTEMPTS);

            $budget = new RequestBudget($this->directory);
            $status = $budget->status();
            self::assertSame(PHP_INT_MAX, $status['limitMicros']);
            self::assertSame(PHP_INT_MAX, $status['maxAttempts']);
            self::assertSame(0, $status['attempts']);
            self::assertTrue($status['canRequest']);

            $factoryBudget = RequestBudget::forRuntime($this->directory);
            self::assertSame($status, $factoryBudget->status());
        } finally {
            $previous === false ? putenv('PROOFPATCH_MODEL_MAX_ATTEMPTS') : putenv('PROOFPATCH_MODEL_MAX_ATTEMPTS='.$previous);
            if ($prevEnv !== null) {
                $_ENV['PROOFPATCH_MODEL_MAX_ATTEMPTS'] = $prevEnv;
            }
            if ($prevServer !== null) {
                $_SERVER['PROOFPATCH_MODEL_MAX_ATTEMPTS'] = $prevServer;
            }
        }
    }

    public function testInvalidLedgerFailsClosed(): void
    {
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/runtime-ledger.json', '{"version":1,"attempts":[{"reservedMicros":-1,"status":"received"}]}');
        $this->expectException(\RuntimeException::class);
        (new RequestBudget($this->directory))->status();
    }

    public function testAttemptLimitCanBeSetWithoutChangingTheReservationLimit(): void
    {
        $previous = getenv('PROOFPATCH_MODEL_MAX_ATTEMPTS');
        try {
            putenv('PROOFPATCH_MODEL_MAX_ATTEMPTS=12');
            $status = RequestBudget::forRuntime($this->directory)->status();
            self::assertSame(12, $status['maxAttempts']);
            self::assertSame(PHP_INT_MAX, $status['limitMicros']);

            putenv('PROOFPATCH_MODEL_MAX_ATTEMPTS=101');
            try {
                RequestBudget::forRuntime($this->directory);
                self::fail('An excessive attempt limit must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('between 1 and 100', $error->getMessage());
            }
        } finally {
            $previous === false ? putenv('PROOFPATCH_MODEL_MAX_ATTEMPTS') : putenv('PROOFPATCH_MODEL_MAX_ATTEMPTS='.$previous);
        }
    }
}
