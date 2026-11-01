<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Evidence\CheckRunDirectory;

final class CheckRunDirectoryTest extends TestCase
{
    private const RUN_ID = '20261101T103114Z-d959456f5271';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/proofpatch-rundir-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) glob($path.'/*') as $child) {
            $this->remove($child);
        }
        rmdir($path);
    }

    public function testResolvesDirectChildDirectory(): void
    {
        mkdir($this->root.'/'.self::RUN_ID, 0700, true);

        self::assertSame(realpath($this->root.'/'.self::RUN_ID), CheckRunDirectory::resolve($this->root, self::RUN_ID));
    }

    public function testRejectsMalformedIds(): void
    {
        foreach (['', 'nope', '../..', self::RUN_ID.'/../..', "..\0"] as $bad) {
            self::assertNull(CheckRunDirectory::resolve($this->root, $bad), $bad);
        }
    }

    public function testRejectsMissingPathAndPlainFiles(): void
    {
        self::assertNull(CheckRunDirectory::resolve($this->root, self::RUN_ID));
        file_put_contents($this->root.'/'.self::RUN_ID, 'x');
        self::assertNull(CheckRunDirectory::resolve($this->root, self::RUN_ID));
    }

    public function testRefusesSymlinkedEntry(): void
    {
        $outside = sys_get_temp_dir().'/proofpatch-rundir-out-'.bin2hex(random_bytes(8));
        mkdir($outside, 0700, true);
        try {
            symlink($outside, $this->root.'/'.self::RUN_ID);
            self::assertNull(CheckRunDirectory::resolve($this->root, self::RUN_ID));
        } finally {
            $this->remove($outside);
        }
    }

    public function testRefusesSymlinkedRoot(): void
    {
        $real = $this->root.'/real';
        mkdir($real.'/'.self::RUN_ID, 0700, true);
        $link = sys_get_temp_dir().'/proofpatch-rundir-link-'.bin2hex(random_bytes(8));
        symlink($real, $link);
        try {
            self::assertNull(CheckRunDirectory::resolve($link, self::RUN_ID));
        } finally {
            unlink($link);
        }
    }
}
