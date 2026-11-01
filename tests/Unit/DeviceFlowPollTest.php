<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Settings\DeviceFlowPoll;
use ProofPatch\Application\Settings\DeviceFlowSession;

final class DeviceFlowPollTest extends TestCase
{
    public function testSessionCheck(): void
    {
        $live = ['clientId' => 'c', 'deviceCode' => 'd', 'expiresAt' => 1000];
        self::assertNull(DeviceFlowSession::check($live, 999));
        self::assertNull(DeviceFlowSession::check($live, 1000));
        self::assertSame('expired', DeviceFlowSession::check($live, 1001));
        self::assertSame('expired', DeviceFlowSession::check(['clientId' => 'c', 'deviceCode' => 'd'], 1000));
        self::assertSame('missing', DeviceFlowSession::check(null, 0));
        self::assertSame('missing', DeviceFlowSession::check('junk', 0));
        self::assertSame('missing', DeviceFlowSession::check(['clientId' => 'c'], 0));
        self::assertSame('missing', DeviceFlowSession::check([], 0));
    }

    public function testPollOutcomeMapping(): void
    {
        self::assertSame('authorized', DeviceFlowPoll::outcome(['status' => 'authorized', 'accessToken' => 't']));
        self::assertSame('pending', DeviceFlowPoll::outcome(['status' => 'pending']));
        self::assertSame('slow_down', DeviceFlowPoll::outcome(['status' => 'slow_down']));
        self::assertSame('expired', DeviceFlowPoll::outcome(['status' => 'expired']));
        self::assertSame('denied', DeviceFlowPoll::outcome(['status' => 'denied']));
        // Authorized without a token cannot proceed.
        self::assertSame('error', DeviceFlowPoll::outcome(['status' => 'authorized']));
        self::assertSame('error', DeviceFlowPoll::outcome(['status' => 'weird']));
    }
}
