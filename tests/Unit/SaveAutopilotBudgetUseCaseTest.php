<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Settings\SaveAutopilotBudgetInput;
use ProofPatch\Application\Settings\SaveAutopilotBudgetUseCase;

final class SaveAutopilotBudgetUseCaseTest extends TestCase
{
    private const ATTEMPTS_CEILING = 100000;

    private const MICROS_CEILING = 100000000000;

    private function execute(array $raw): \ProofPatch\Application\Settings\SaveAutopilotBudgetResult
    {
        return (new SaveAutopilotBudgetUseCase())->execute(
            SaveAutopilotBudgetInput::fromRaw($raw, self::ATTEMPTS_CEILING, self::MICROS_CEILING)
        );
    }

    public function testValidCapsProduceSetMessage(): void
    {
        $result = $this->execute(['maxAttempts' => '25', 'maxDollars' => '2.50']);

        self::assertTrue($result->ok);
        self::assertFalse($result->clears);
        self::assertSame(25, $result->maxAttempts);
        self::assertSame(2500000, $result->maxMicros);
        self::assertSame('Autopilot cap set: 25 attempts and $2.50 of spend. Autopilot reviews stop there.', $result->message);
    }

    public function testSingleCapOmitsTheOther(): void
    {
        $attempts = $this->execute(['maxAttempts' => '10', 'maxDollars' => '']);
        self::assertSame(10, $attempts->maxAttempts);
        self::assertNull($attempts->maxMicros);
        self::assertSame('Autopilot cap set: 10 attempts of spend. Autopilot reviews stop there.', $attempts->message);

        $spend = $this->execute(['maxAttempts' => '', 'maxDollars' => '1']);
        self::assertNull($spend->maxAttempts);
        self::assertSame(1000000, $spend->maxMicros);
        self::assertSame('Autopilot cap set: $1.00 of spend. Autopilot reviews stop there.', $spend->message);
    }

    public function testBlankInputsClear(): void
    {
        $result = $this->execute(['maxAttempts' => '  ', 'maxDollars' => '']);

        self::assertTrue($result->ok);
        self::assertTrue($result->clears);
        self::assertSame('Autopilot caps cleared. Automatic reviews are bounded only by your provider quota.', $result->message);
    }

    public function testWhitespaceIsTrimmedAndDollarsRound(): void
    {
        $result = $this->execute(['maxAttempts' => ' 7 ', 'maxDollars' => ' 2.505 ']);

        self::assertSame(7, $result->maxAttempts);
        self::assertSame(2505000, $result->maxMicros);
    }

    public function testCapsClampToCeilings(): void
    {
        $result = $this->execute(['maxAttempts' => '250000', 'maxDollars' => '250000']);

        self::assertSame(self::ATTEMPTS_CEILING, $result->maxAttempts);
        self::assertSame(self::MICROS_CEILING, $result->maxMicros);
    }

    public function testInvalidAttemptCapIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempt cap must be a whole number of at least 1.');
        $this->execute(['maxAttempts' => '0', 'maxDollars' => '']);
    }

    public function testInvalidSpendCapIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Spend cap must be a positive dollar amount.');
        $this->execute(['maxAttempts' => '', 'maxDollars' => '-5']);
    }
}
