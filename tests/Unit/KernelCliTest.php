<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class KernelCliTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        KernelCli::clearSelectedRepo();
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testBootLinesIdentifyTheKernel(): void
    {
        $lines = KernelCli::bootLines();

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('ProofPatcher Kernel', $lines[0]);
    }

    public function testRepoValidationAcceptsOwnerSlashName(): void
    {
        $this->assertTrue(KernelCli::isValidRepo('octo/hello-world'));
        $this->assertTrue(KernelCli::isValidRepo('a/b'));
        $this->assertFalse(KernelCli::isValidRepo(''));
        $this->assertFalse(KernelCli::isValidRepo('no-slash'));
        $this->assertFalse(KernelCli::isValidRepo('/leading'));
        $this->assertFalse(KernelCli::isValidRepo('trailing/'));
        $this->assertFalse(KernelCli::isValidRepo('a/b/c'));
        $this->assertFalse(KernelCli::isValidRepo('a/b c'));
        $this->assertFalse(KernelCli::isValidRepo(str_repeat('a', 120) . '/b'));
    }

    public function testSelectionRoundTrips(): void
    {
        $this->assertNull(KernelCli::getSelectedRepo());

        KernelCli::setSelectedRepo('octo/hello-world');

        $this->assertSame('octo/hello-world', KernelCli::getSelectedRepo());

        KernelCli::clearSelectedRepo();

        $this->assertNull(KernelCli::getSelectedRepo());
    }

    public function testSelectionRejectsInvalidRepo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KernelCli::setSelectedRepo('not a repo');
    }

    public function testSelectionFileIsOwnerOnly(): void
    {
        KernelCli::setSelectedRepo('octo/hello-world');

        $perms = fileperms(KernelCli::selectionPath());
        $this->assertSame(0, $perms & 0077);
    }

    public function testCorruptSelectionReadsAsNull(): void
    {
        file_put_contents(KernelCli::selectionPath(), "garbage\n///\n");

        $this->assertNull(KernelCli::getSelectedRepo());
    }

    public function testReportNamesWhereWhyAndFix(): void
    {
        $report = KernelCli::report('kernel:autopilot · cannot start', 'auto-review is off for acme/repo', 'php artisan kernel:select-repo --enable-review acme/repo');

        $this->assertStringContainsString('✕ kernel:autopilot · cannot start', $report);
        $this->assertStringContainsString('why: auto-review is off for acme/repo', $report);
        $this->assertStringContainsString('fix: php artisan kernel:select-repo --enable-review acme/repo', $report);
    }

    public function testReportOmitsEmptyFix(): void
    {
        $report = KernelCli::report('kernel shell · autopilot', 'no repository selected');

        $this->assertStringContainsString('✕ kernel shell · autopilot', $report);
        $this->assertStringNotContainsString('fix:', $report);
    }

    public function testAutopilotBlockerFlagsEveryPrecondition(): void
    {
        $this->assertSame(
            ['why' => 'no repository selected', 'fix' => 'php artisan kernel:select-repo'],
            KernelCli::autopilotBlocker(null, ['acme/repo'], true)
        );
        $this->assertSame(
            ['why' => 'expected owner/repo, got \'nope\'', 'fix' => 'php artisan kernel:select-repo --list'],
            KernelCli::autopilotBlocker('nope', ['acme/repo'], true)
        );
        $this->assertSame(
            ['why' => 'evil/other is not in the current GitHub grant', 'fix' => 'php artisan kernel:select-repo --list'],
            KernelCli::autopilotBlocker('evil/other', ['acme/repo'], true)
        );
        $this->assertSame(
            ['why' => 'auto-review is off for acme/repo', 'fix' => 'php artisan kernel:select-repo --enable-review acme/repo'],
            KernelCli::autopilotBlocker('acme/repo', ['acme/repo'], false)
        );
        $this->assertNull(KernelCli::autopilotBlocker('acme/repo', ['acme/repo'], true));
        // Case-insensitive grant match.
        $this->assertNull(KernelCli::autopilotBlocker('ACME/REPO', ['acme/repo'], true));
    }
}
