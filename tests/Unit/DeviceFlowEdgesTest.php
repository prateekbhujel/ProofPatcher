<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Settings\DeviceFlowClientId;
use ProofPatch\Application\Settings\DisconnectRedirect;

final class DeviceFlowEdgesTest extends TestCase
{
    public function testDisconnectTargetAllowlist(): void
    {
        foreach (['/issues', '/repositories', '/settings', '/checks'] as $allowed) {
            self::assertSame($allowed, DisconnectRedirect::target($allowed), $allowed);
        }
        foreach (['', '/', '/evil', '/settings?x=1', 'https://evil.invalid', '//evil.invalid', '/Repositories', 0, null, false] as $bad) {
            self::assertSame('/repositories', DisconnectRedirect::target($bad), var_export($bad, true));
        }
    }

    public function testClientIdResolutionOrder(): void
    {
        self::assertSame('form-id', DeviceFlowClientId::resolve('form-id', 'env-id', 'fb-id'));
        self::assertSame('env-id', DeviceFlowClientId::resolve('', 'env-id', 'fb-id'));
        self::assertSame('env-id', DeviceFlowClientId::resolve(null, 'env-id', 'fb-id'));
        self::assertSame('fb-id', DeviceFlowClientId::resolve('', '', 'fb-id'));
        self::assertSame('form-id', DeviceFlowClientId::resolve('  form-id  ', 'env-id', 'fb-id'));
        self::assertSame('0x', DeviceFlowClientId::resolve(0, '0x', 'fb'));
    }

    public function testClientIdFailsClosedWhenEmpty(): void
    {
        foreach ([['', false, null], ['   ', 'env-id', 'fb-id']] as [$input, $primary, $fallback]) {
            try {
                DeviceFlowClientId::resolve($input, $primary, $fallback);
                self::fail('empty client id must throw');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('A GitHub App Client ID is required. Set PROOFPATCH_GITHUB_CLIENT_ID or provide it in the form.', $e->getMessage());
            }
        }
    }
}
