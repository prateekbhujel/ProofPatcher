<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Settings\GitHubConnectionProjection;

final class GitHubConnectionProjectionTest extends TestCase
{
    /** @return array<string, mixed> */
    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'auth' => [
                'token' => 't',
                'user' => ['id' => 7, 'login' => 'octo'],
                'repositories' => [['fullName' => 'acme/repo']],
                'repositoriesStatus' => 'ok',
            ],
            'deviceFlow' => [
                'userCode' => 'AB-CD', 'verificationUri' => 'https://example.invalid',
                'expiresAt' => 99, 'interval' => 5,
            ],
            'clientIdConfigured' => true,
            'clientId' => 'Iv1.abc',
            'selectedRepo' => 'acme/repo',
            'statusMessage' => 'hi',
            'errorMessage' => null,
        ], $overrides);
    }

    public function testBuildsConnectedPropsInOrder(): void
    {
        $props = GitHubConnectionProjection::build($this->ctx());

        self::assertSame(
            ['isConfigured', 'clientId', 'isConnected', 'user', 'repositories', 'repositoriesStatus',
                'selectedRepo', 'deviceFlow', 'statusMessage', 'errorMessage'],
            array_keys($props)
        );
        self::assertTrue($props['isConfigured']);
        self::assertSame('Iv1.abc', $props['clientId']);
        self::assertTrue($props['isConnected']);
        self::assertSame(['id' => 7, 'login' => 'octo'], $props['user']);
        self::assertSame([['fullName' => 'acme/repo']], $props['repositories']);
        self::assertSame('ok', $props['repositoriesStatus']);
        self::assertSame('acme/repo', $props['selectedRepo']);
        self::assertSame(
            ['userCode' => 'AB-CD', 'verificationUri' => 'https://example.invalid', 'expiresAt' => 99, 'interval' => 5],
            $props['deviceFlow']
        );
        self::assertSame('hi', $props['statusMessage']);
        self::assertNull($props['errorMessage']);
    }

    public function testDisconnectedAuthDegradesHonestly(): void
    {
        $props = GitHubConnectionProjection::build($this->ctx([
            'auth' => null, 'deviceFlow' => null, 'clientIdConfigured' => false,
            'clientId' => null, 'selectedRepo' => null,
        ]));

        self::assertFalse($props['isConfigured']);
        self::assertNull($props['clientId']);
        self::assertFalse($props['isConnected']);
        self::assertNull($props['user']);
        self::assertSame([], $props['repositories']);
        self::assertNull($props['repositoriesStatus']);
        self::assertNull($props['selectedRepo']);
        self::assertNull($props['deviceFlow']);
    }

    public function testExtrasLandBetweenSelectionAndDeviceFlow(): void
    {
        $props = GitHubConnectionProjection::build($this->ctx([
            'extras' => ['canCapturePrivateTestRepo' => true, 'repoPulls' => [1], 'repoPullsStatus' => null],
        ]));

        self::assertSame(
            ['isConfigured', 'clientId', 'isConnected', 'user', 'repositories', 'repositoriesStatus',
                'selectedRepo', 'canCapturePrivateTestRepo', 'repoPulls', 'repoPullsStatus',
                'deviceFlow', 'statusMessage', 'errorMessage'],
            array_keys($props)
        );
        self::assertSame([1], $props['repoPulls']);
        self::assertTrue($props['canCapturePrivateTestRepo']);
    }
}
