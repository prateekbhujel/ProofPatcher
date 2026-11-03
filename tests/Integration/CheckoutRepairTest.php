<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProofPatch\Verifier\LockedCheckoutRunner;
use ProofPatch\Verifier\PixelDiffGuard;
use Symfony\Component\Process\Process;

final class CheckoutRepairTest extends TestCase
{
    private const MASK = ['x' => 300, 'y' => 280, 'width' => 380, 'height' => 140];

    public function testOriginalFailureValidRepairAndDamagingShortcut(): void
    {
        $project = dirname(__DIR__, 2);
        $root = sys_get_temp_dir().'/proofpatch-'.bin2hex(random_bytes(8));
        $source = $root.'/source';
        mkdir($source.'/public/fixtures', 0700, true);
        copy($project.'/public/fixtures/checkout.html', $source.'/public/fixtures/checkout.html');
        $this->command(['git', 'init', '--quiet', '--initial-branch=main', $source]);
        $this->command(['git', '-C', $source, 'add', '.']);
        $this->command(['git', '-C', $source, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
            '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'fixture']);
        $base = trim($this->command(['git', '-C', $source, 'rev-parse', 'HEAD']));
        $locked = $project.'/tests/Locked/CheckoutLockedTest.php';
        $lockedHash = hash_file('sha256', $locked);
        $evidence = $project.'/storage/app/private/proofpatch/first-slice/'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
        mkdir($evidence, 0700, true);
        $worktrees = [];
        try {
            foreach (['baseline', 'valid', 'shortcut'] as $case) {
                $path = $root.'/'.$case;
                $this->command(['git', '-C', $source, 'worktree', 'add', '--quiet', '--detach', $path, $base]);
                $worktrees[] = $path;
                $fixture = $path.'/public/fixtures/checkout.html';
                $html = file_get_contents($fixture);
                if ($case === 'valid') {
                    $html = str_replace('pointer-events: auto;', 'pointer-events: none;', $html, $replacements);
                    self::assertSame(1, $replacements);
                } elseif ($case === 'shortcut') {
                    $html = str_replace('</style>', "#promo-banner { display: none; }\n</style>", $html, $replacements);
                    self::assertSame(1, $replacements);
                }
                file_put_contents($fixture, $html);
                $runs[$case] = $this->runLockedTest($path, $evidence.'/'.$case, $project);
                $runs[$case]['patchSha256'] = hash('sha256', $this->command(['git', '-C', $path, 'diff', '--', 'public/fixtures/checkout.html']));
            }
            $guard = new PixelDiffGuard();
            $validDiff = $guard->compare($evidence.'/baseline/checkout.png', $evidence.'/valid/checkout.png', self::MASK);
            $shortcutDiff = $guard->compare($evidence.'/baseline/checkout.png', $evidence.'/shortcut/checkout.png', self::MASK);
            $summary = ['baseCommit' => $base, 'lockedTestSha256' => $lockedHash, 'mask' => self::MASK,
                'runs' => $runs, 'validVisual' => $validDiff, 'shortcutVisual' => $shortcutDiff];
            file_put_contents($evidence.'/result.json', json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");

            self::assertSame(1, $runs['baseline']['exitCode'], $runs['baseline']['output']);
            self::assertSame('Standard', $runs['baseline']['observation']['selected']);
            self::assertTrue($runs['baseline']['observation']['neighborWorks']);
            self::assertSame(0, $runs['valid']['exitCode'], $runs['valid']['output']);
            self::assertTrue($validDiff['passed']);
            self::assertSame(1, $runs['shortcut']['exitCode'], $runs['shortcut']['output']);
            self::assertSame('Express Next-Day', $runs['shortcut']['observation']['selected']);
            self::assertFalse($runs['shortcut']['observation']['banner']['visible']);
            self::assertFalse($shortcutDiff['passed']);
            self::assertSame($lockedHash, hash_file('sha256', $locked));
            self::assertStringContainsString('pointer-events: auto;', file_get_contents($source.'/public/fixtures/checkout.html'));
        } finally {
            foreach ($worktrees as $path) {
                $this->command(['git', '-C', $source, 'worktree', 'remove', '--force', $path]);
            }
            $this->removeDirectory($root);
        }
    }

    private function runLockedTest(string $worktree, string $evidence, string $project): array
    {
        return (new LockedCheckoutRunner($project))->run($worktree, $evidence);
    }

    private function command(array $argv): string
    {
        $process = new Process($argv, null, $this->environment());
        $process->setTimeout(15)->mustRun();
        return $process->getOutput();
    }

    private function environment(array $extra = []): array
    {
        $environment = array_fill_keys(array_keys(getenv()), false);
        foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
            $environment[$key] = getenv($key) ?: false;
        }
        return array_merge($environment, ['LANG' => 'C', 'LC_ALL' => 'C'], $extra);
    }

    private function removeDirectory(string $path): void
    {
        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
