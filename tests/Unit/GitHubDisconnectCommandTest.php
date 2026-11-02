<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Console\Commands\GitHubDisconnectCommand;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Models\AutomaticInlineSetting;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;

final class GitHubDisconnectCommandTest extends TestCase
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

    public function testDisconnectClearsStoredSignInSelectionAndConsent(): void
    {
        GitHubTokenStore::save('doomed-token', ['id' => 11, 'login' => 'leaver'], [['fullName' => 'acme/repo']]);
        KernelCli::setSelectedRepo('acme/repo');
        AutomaticInlineSetting::setForOwner(11, 'acme/repo', true);

        $command = new GitHubDisconnectCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('@leaver', $tester->getDisplay());
        $this->assertNull(GitHubTokenStore::getToken());
        $this->assertNull(KernelCli::getSelectedRepo());
        $this->assertFalse(AutomaticInlineSetting::enabled(11, 'acme/repo'));
        $this->assertStringNotContainsString('doomed-token', $tester->getDisplay());
    }

    public function testDisconnectWithoutConnectionIsHarmless(): void
    {
        $command = new GitHubDisconnectCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('nothing to clear', $tester->getDisplay());
    }
}
