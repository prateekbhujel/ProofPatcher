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
}
