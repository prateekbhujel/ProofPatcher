<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\CaptureRoutePolicy;

final class CaptureRoutePolicyTest extends TestCase
{
    private const CONFIGURED = 'acme/private-test';

    public function testRoutesConfiguredTestRepoToAuthenticatedCapture(): void
    {
        self::assertSame(
            ['repository' => self::CONFIGURED, 'number' => 8],
            CaptureRoutePolicy::privateTestRepoTarget('https://github.com/acme/private-test/pull/8', self::CONFIGURED)
        );
        // Shorthand and trailing slash normalize to the same target.
        self::assertSame(
            ['repository' => self::CONFIGURED, 'number' => 8],
            CaptureRoutePolicy::privateTestRepoTarget('acme/private-test#8', self::CONFIGURED)
        );
        self::assertSame(
            ['repository' => self::CONFIGURED, 'number' => 10],
            CaptureRoutePolicy::privateTestRepoTarget('https://github.com/acme/private-test/pull/10/', self::CONFIGURED)
        );
        // Owner/repo matches case-insensitively but the configured name wins.
        self::assertSame(
            ['repository' => self::CONFIGURED, 'number' => 8],
            CaptureRoutePolicy::privateTestRepoTarget('https://github.com/ACME/Private-Test/pull/8', self::CONFIGURED)
        );
    }

    public function testOtherTargetsFallThroughToPublicReader(): void
    {
        self::assertNull(CaptureRoutePolicy::privateTestRepoTarget('https://github.com/acme/other/pull/8', self::CONFIGURED));
        self::assertNull(CaptureRoutePolicy::privateTestRepoTarget('https://github.com/acme/private-test/pull/8', null));
        self::assertNull(CaptureRoutePolicy::privateTestRepoTarget('not a url', self::CONFIGURED));
        self::assertNull(CaptureRoutePolicy::privateTestRepoTarget('https://github.com/acme/private-test/issues/8', self::CONFIGURED));
        self::assertNull(CaptureRoutePolicy::privateTestRepoTarget('', self::CONFIGURED));
    }
}
