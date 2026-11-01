<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Console\Commands\GitHubConnectCommand;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubConnectCommandTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private mixed $app = null;

    private ?string $tokenBackup = null;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->app = $this->bootstrapAndMigrateIsolatedDatabase();

        $path = GitHubTokenStore::getStoragePath();
        if (is_file($path)) {
            $this->tokenBackup = (string) file_get_contents($path);
        }
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();
    }

    protected function tearDown(): void
    {
        GitHubTokenStore::clear();
        if ($this->tokenBackup !== null) {
            file_put_contents(GitHubTokenStore::getStoragePath(), $this->tokenBackup, LOCK_EX);
            @chmod(GitHubTokenStore::getStoragePath(), 0600);
        }
        $this->tokenBackup = null;
        KernelCli::clearSelectedRepo();
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->envBackup = [];
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function clearClientIdEnv(): void
    {
        foreach (['PROOFPATCH_GITHUB_CLIENT_ID', 'GITHUB_APP_CLIENT_ID'] as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /** @return array{exit: int, display: string} */
    private function runCommand(MockHttpClient $http, array $input = []): array
    {
        $command = new GitHubConnectCommand($http);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exit = $tester->execute($input);

        return ['exit' => $exit, 'display' => $tester->getDisplay()];
    }

    private static function jsonResponse(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'headers' => ['Content-Type: application/json'],
        ]);
    }

    private function initiateResponse(): MockResponse
    {
        return self::jsonResponse([
            'device_code' => 'device-code-1',
            'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://github.com/login/device',
            'expires_in' => 900,
            'interval' => 5,
        ]);
    }

    /** @return list<MockResponse> */
    private function identityResponses(): array
    {
        return [
            self::jsonResponse(['id' => 1, 'login' => 'octocat']),
            self::jsonResponse(['total_count' => 1, 'installations' => [['id' => 7]]]),
            self::jsonResponse(['total_count' => 1, 'repositories' => [[
                'id' => 11, 'name' => 'repo', 'full_name' => 'acme/repo', 'private' => false,
                'default_branch' => 'main', 'html_url' => 'https://github.com/acme/repo',
                'owner' => ['login' => 'acme'],
            ]]]),
        ];
    }

    public function testRequiresClientId(): void
    {
        $this->clearClientIdEnv();

        $result = $this->runCommand(new MockHttpClient(), []);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('PROOFPATCH_GITHUB_CLIENT_ID', $result['display']);
        $this->assertNull(GitHubTokenStore::getToken());
    }

    public function testAlreadyConnectedSkipsNetwork(): void
    {
        GitHubTokenStore::save('existing-token', ['id' => 1, 'login' => 'octocat'], [['fullName' => 'acme/repo']]);

        $result = $this->runCommand(new MockHttpClient(), ['--client-id' => 'test-client']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Already connected', $result['display']);
        $this->assertStringContainsString('@octocat', $result['display']);
        $this->assertSame('existing-token', GitHubTokenStore::getToken());
    }

    public function testFullDeviceFlowConnectsAndStoresToken(): void
    {
        KernelCli::setSelectedRepo('acme/stale');
        $http = new MockHttpClient([
            $this->initiateResponse(),
            self::jsonResponse(['error' => 'authorization_pending']),
            self::jsonResponse(['access_token' => 'test-token-value', 'token_type' => 'bearer']),
            ...$this->identityResponses(),
        ]);

        $result = $this->runCommand($http, ['--client-id' => 'test-client', '--interval' => '0']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('ABCD-1234', $result['display']);
        $this->assertStringContainsString('https://github.com/login/device', $result['display']);
        $this->assertStringContainsString('@octocat', $result['display']);
        $this->assertStringContainsString('1 repo', $result['display']);
        $this->assertStringNotContainsString('test-token-value', $result['display']);
        $this->assertSame('test-token-value', GitHubTokenStore::getToken());
        $this->assertNull(KernelCli::getSelectedRepo());
    }

    public function testForcedReconnectDoesNotRestorePreviousAutomaticPostingConsent(): void
    {
        GitHubTokenStore::save('old-token', ['id' => 1, 'login' => 'octocat'], [['fullName' => 'acme/repo']]);
        \ProofPatch\Models\AutomaticInlineSetting::setForOwner(1, 'octocat/repo', true);
        $http = new MockHttpClient([
            $this->initiateResponse(),
            self::jsonResponse(['access_token' => 'new-token-value', 'token_type' => 'bearer']),
            ...$this->identityResponses(),
        ]);

        $result = $this->runCommand($http, ['--client-id' => 'test-client', '--interval' => '0', '--force' => true]);
        $this->assertSame(0, $result['exit']);
        $this->assertSame('new-token-value', GitHubTokenStore::getToken());
        $this->assertFalse(\ProofPatch\Models\AutomaticInlineSetting::enabled(1, 'octocat/repo'));
    }

    public function testDeniedFailsClosedWithoutStoring(): void
    {
        $http = new MockHttpClient([
            $this->initiateResponse(),
            self::jsonResponse(['error' => 'access_denied']),
        ]);

        $result = $this->runCommand($http, ['--client-id' => 'test-client', '--interval' => '0']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('declined', $result['display']);
        $this->assertNull(GitHubTokenStore::getToken());
    }

    public function testExpiredCodeFailsClosed(): void
    {
        $http = new MockHttpClient([
            $this->initiateResponse(),
            self::jsonResponse(['error' => 'expired_token']),
        ]);

        $result = $this->runCommand($http, ['--client-id' => 'test-client', '--interval' => '0']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('expired', $result['display']);
        $this->assertNull(GitHubTokenStore::getToken());
    }

    public function testTimeoutFailsClosed(): void
    {
        $http = new MockHttpClient([
            $this->initiateResponse(),
            self::jsonResponse(['error' => 'authorization_pending']),
        ]);

        $result = $this->runCommand($http, ['--client-id' => 'test-client', '--interval' => '0', '--timeout' => '0']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('timed out', $result['display']);
        $this->assertNull(GitHubTokenStore::getToken());
    }

    public function testInvalidIdentityFailsClosed(): void
    {
        $http = new MockHttpClient([
            $this->initiateResponse(),
            self::jsonResponse(['access_token' => 'test-token-value', 'token_type' => 'bearer']),
            self::jsonResponse(['login' => 'ghost']),
        ]);

        $result = $this->runCommand($http, ['--client-id' => 'test-client', '--interval' => '0']);

        $this->assertSame(1, $result['exit']);
        $this->assertNull(GitHubTokenStore::getToken());
    }
}
