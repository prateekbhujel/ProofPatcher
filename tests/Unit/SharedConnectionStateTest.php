<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Settings\AppRedirectTarget;
use ProofPatch\Application\Settings\ConnectionNextStep;
use ProofPatch\Application\Settings\GitHubConnectionState;

final class SharedConnectionStateTest extends TestCase
{
    public function testConnectedRequiresTokenAndVerifiedUser(): void
    {
        self::assertTrue(GitHubConnectionState::isConnected(['token' => 't', 'user' => ['id' => 1, 'login' => 'a']]));
        self::assertTrue(GitHubConnectionState::isConnected(['token' => 't', 'user' => ['login' => 'a']]));
    }

    public function testStaleTokenWithoutUserIsNotConnected(): void
    {
        // The middleware nulls the user when GitHub rejects the token but
        // keeps the token for re-verification: that state must read as
        // disconnected on every surface.
        foreach ([null, [], 'x', ['token' => 't'], ['token' => 't', 'user' => null], ['token' => 't', 'user' => []], ['token' => '  ', 'user' => ['id' => 1]], ['user' => ['id' => 1]]] as $auth) {
            self::assertFalse(GitHubConnectionState::isConnected($auth), var_export($auth, true));
        }
    }

    public function testRedirectTargetAllowlist(): void
    {
        foreach (['/', '/issues', '/repositories', '/settings', '/checks'] as $allowed) {
            self::assertSame($allowed, AppRedirectTarget::target($allowed), $allowed);
        }
        foreach (['', '/evil', '/settings?x=1', 'https://evil.invalid', '//evil.invalid', '/Repositories', 0, null, false] as $bad) {
            self::assertSame('/repositories', AppRedirectTarget::target($bad), var_export($bad, true));
        }
    }

    public function testRedirectTargetHonorsFallback(): void
    {
        self::assertSame('/issues', AppRedirectTarget::target('/evil', '/issues'));
        self::assertSame('/repositories', AppRedirectTarget::target('/evil', '/evil'));
    }

    public function testNextStepKeysFollowConnectionPipeline(): void
    {
        self::assertSame('connect', ConnectionNextStep::key(false, 0, null));
        self::assertSame('grant', ConnectionNextStep::key(true, 0, null));
        self::assertSame('select_repo', ConnectionNextStep::key(true, 2, null));
        self::assertSame('select_repo', ConnectionNextStep::key(true, 2, '  '));
        self::assertSame('target', ConnectionNextStep::key(true, 2, 'acme/repo'));
    }

    public function testNextStepPayloadGuidesToTheRightPage(): void
    {
        $connect = ConnectionNextStep::for(false, 0, null);
        self::assertSame(['key' => 'connect', 'title' => 'Connect GitHub', 'detail' => 'Link your account.', 'href' => '/issues', 'cta' => 'Continue with GitHub'], $connect);

        $grant = ConnectionNextStep::for(true, 0, null);
        self::assertSame('grant', $grant['key']);
        self::assertSame('/settings', $grant['href']);

        $select = ConnectionNextStep::for(true, 3, null);
        self::assertSame('select_repo', $select['key']);
        self::assertSame('/issues', $select['href']);
        self::assertSame('Choose a repository', $select['cta']);

        $target = ConnectionNextStep::for(true, 3, 'acme/repo');
        self::assertSame('target', $target['key']);
        self::assertSame('Open the workbench', $target['cta']);
    }
}
