<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Console\Commands\KernelStatusCommand;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;

final class KernelStatusCommandTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private mixed $app = null;

    private ?string $tokenBackup = null;

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
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function connect(string $login = 'maintainer', array $repos = [['fullName' => 'acme/repo']]): void
    {
        GitHubTokenStore::save('test-token-value', ['id' => 1, 'login' => $login], $repos);
    }

    /** @return array{exit: int, display: string} */
    private function runCommand(array $input = []): array
    {
        $command = new KernelStatusCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exit = $tester->execute($input);

        return ['exit' => $exit, 'display' => $tester->getDisplay()];
    }

    public function testDisconnectedStatusPointsAtConnect(): void
    {
        $result = $this->runCommand([]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('ProofPatcher Kernel', $result['display']);
        $this->assertStringContainsString('not connected', $result['display']);
        $this->assertStringContainsString('github:connect', $result['display']);
        $this->assertStringNotContainsString('test-token-value', $result['display']);
    }

    public function testConnectedStatusShowsUserReposAndSelection(): void
    {
        $this->connect('maintainer', [['fullName' => 'acme/repo'], ['fullName' => 'acme/other']]);
        KernelCli::setSelectedRepo('acme/repo');

        $result = $this->runCommand([]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('@maintainer', $result['display']);
        $this->assertStringContainsString('2 repos', $result['display']);
        $this->assertStringContainsString('acme/repo', $result['display']);
        $this->assertStringContainsString('kernel:select-repo', $result['display']);
    }

    public function testNextStepProgressesToAutopilot(): void
    {
        $this->connect();
        KernelCli::setSelectedRepo('acme/repo');
        RepositoryAutoReviewSetting::setRepoEnabled('acme/repo', true);

        $result = $this->runCommand([]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('kernel:autopilot', $result['display']);
    }

    public function testStatsCountOnlyAuthorizedRepos(): void
    {
        $this->connect('maintainer', [['fullName' => 'acme/repo']]);
        $row = static fn(string $repo, string $status): array => [
            'repository' => $repo, 'pull_number' => 1,
            'head_sha' => str_repeat('1', 40), 'base_sha' => str_repeat('2', 40),
            'status' => $status, 'is_public' => true, 'published_status' => 'local_only',
        ];
        AutoReviewResult::create($row('acme/repo', 'completed'));
        AutoReviewResult::create($row('evil/other', 'completed'));

        $result = $this->runCommand([]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('1 completed', $result['display']);
        $this->assertStringNotContainsString('evil/other', $result['display']);
    }

    public function testJsonOutputIsStructured(): void
    {
        $this->connect();
        KernelCli::setSelectedRepo('acme/repo');

        $result = $this->runCommand(['--json' => true]);

        $this->assertSame(0, $result['exit']);
        $data = json_decode($result['display'], true);
        $this->assertIsArray($data);
        $this->assertTrue($data['connected']);
        $this->assertSame('acme/repo', $data['selectedRepo']);
        $this->assertArrayHasKey('provider', $data);
        $this->assertArrayHasKey('budget', $data);
        $this->assertArrayHasKey('worker', $data);
        $this->assertArrayHasKey('nextStep', $data);
        $this->assertStringNotContainsString('test-token-value', $result['display']);
    }

    public function testCorruptTokenStoreReadsAsDisconnected(): void
    {
        file_put_contents(GitHubTokenStore::getStoragePath(), 'not-json{{{', LOCK_EX);

        $result = $this->runCommand([]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('not connected', $result['display']);
        $this->assertStringContainsString('github:connect', $result['display']);
    }
}
