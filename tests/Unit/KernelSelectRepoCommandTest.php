<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Console\Commands\KernelSelectRepoCommand;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;

final class KernelSelectRepoCommandTest extends TestCase
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

    private function connect(): void
    {
        GitHubTokenStore::save('test-token-value', ['id' => 1, 'login' => 'octocat'], [
            ['fullName' => 'acme/repo'],
            ['fullName' => 'acme/other'],
        ]);
    }

    /** @return array{exit: int, display: string} */
    private function runCommand(array $input = []): array
    {
        $command = new KernelSelectRepoCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exit = $tester->execute($input);

        return ['exit' => $exit, 'display' => $tester->getDisplay()];
    }

    public function testDisconnectedFailsClosed(): void
    {
        $result = $this->runCommand(['repo' => 'acme/repo']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('github:connect', $result['display']);
        $this->assertNull(KernelCli::getSelectedRepo());
    }

    public function testInvalidHandleFailsClosed(): void
    {
        $this->connect();

        $result = $this->runCommand(['repo' => 'not a repo']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('owner/repo', $result['display']);
        $this->assertNull(KernelCli::getSelectedRepo());
    }

    public function testUnauthorizedRepoFailsClosed(): void
    {
        $this->connect();

        $result = $this->runCommand(['repo' => 'evil/other']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('not in the current GitHub grant', $result['display']);
        $this->assertNull(KernelCli::getSelectedRepo());
        $this->assertFalse(RepositoryAutoReviewSetting::isRepoEnabled('evil/other'));
    }

    public function testValidRepoIsSelected(): void
    {
        $this->connect();

        $result = $this->runCommand(['repo' => 'acme/repo']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('acme/repo', KernelCli::getSelectedRepo());
        $this->assertStringContainsString('Selected acme/repo', $result['display']);
    }

    public function testEnableReviewFlagEnablesSetting(): void
    {
        $this->connect();

        $result = $this->runCommand(['repo' => 'acme/repo', '--enable-review' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue(RepositoryAutoReviewSetting::isRepoEnabled('acme/repo'));
        $this->assertStringContainsString('auto-review enabled', $result['display']);
    }

    public function testDisableReviewFlagDisablesSetting(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/repo', true);

        $result = $this->runCommand(['repo' => 'acme/repo', '--disable-review' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertFalse(RepositoryAutoReviewSetting::isRepoEnabled('acme/repo'));
        $this->assertStringContainsString('auto-review disabled', $result['display']);
    }

    public function testListShowsAuthorizedReposWithMarkers(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/repo', true);
        KernelCli::setSelectedRepo('acme/other');

        $result = $this->runCommand(['--list' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('acme/repo', $result['display']);
        $this->assertStringContainsString('acme/other', $result['display']);
        $this->assertStringContainsString('enabled', $result['display']);
        $this->assertStringContainsString('selected', $result['display']);
    }
}
