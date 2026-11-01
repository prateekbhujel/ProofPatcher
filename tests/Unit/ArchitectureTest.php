<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Application layer owns use cases and policies. It must not reach into
 * HTTP, sessions, facades, redirects, or framework helpers: the controller
 * supplies plain data and maps results back to responses.
 */
final class ArchitectureTest extends TestCase
{
    private const FORBIDDEN = [
        'Illuminate\\Http',
        'Illuminate\\Support\\Facades',
        'Inertia\\',
        'Symfony\\Component\\HttpFoundation',
        'app(',
        'config(',
        'storage_path(',
        'base_path(',
        'redirect(',
        'response(',
        '->session(',
        '->attributes->',
    ];

    public function testApplicationLayerHasNoFrameworkCoupling(): void
    {
        $root = dirname(__DIR__, 2) . '/app/Application';
        self::assertDirectoryExists($root);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $violations = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // Strip comments so documentation of the rule is not flagged.
            $code = preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~#[^\n]*~'], '', $code);
            foreach (self::FORBIDDEN as $marker) {
                if (str_contains($code, $marker)) {
                    $violations[] = $file->getFilename() . ': ' . $marker;
                }
            }
        }
        self::assertSame([], $violations, 'Application layer must stay framework-free.');
    }

    /**
     * The hari kernel (app/Hari, namespace Hari\) is pure PHP: no framework,
     * no ProofPatch imports, no facades, no helpers. Extraction later must be
     * a directory move, not a rewrite.
     */
    public function testHariKernelIsPure(): void
    {
        $root = dirname(__DIR__, 2) . '/app/Hari';
        self::assertDirectoryExists($root);
        $forbidden = array_merge(self::FORBIDDEN, [
            'ProofPatch\\',
            'App\\',
            'Illuminate\\',
            'Symfony\\',
            'Laravel\\',
            'use function ',
        ]);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $violations = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $code = preg_replace(['~/\\*.*?\\*/~s', '~//[^\n]*~', '~#[^\n]*~'], '', $code);
            foreach ($forbidden as $marker) {
                if (str_contains($code, $marker)) {
                    $violations[] = $file->getFilename() . ': ' . $marker;
                }
            }
        }
        self::assertSame([], $violations, 'Hari kernel must stay pure PHP.');
    }

    /**
     * Migrated seams stay behind skills: HTTP and console code resolves
     * budget/evidence/review/verify reads through the hari registry instead
     * of touching engines directly. ReviewJobController keeps its own
     * snapshot-store verify (a different, GitHub-coupled API).
     */
    public function testHttpAndConsoleUseSkillsForMigratedSeams(): void
    {
        $roots = [dirname(__DIR__, 2).'/app/Http', dirname(__DIR__, 2).'/app/Console'];
        $markers = [
            'new VerifyCandidateUseCase(',
            'AutoReviewResult::',
            'RequestBudget::forRuntime()->status()',
            'new Receipt(',
            'Receipt::',
        ];
        $violations = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                $code = preg_replace(['~/\\*.*?\\*/~s', '~//[^\n]*~', '~#[^\n]*~'], '', $code);
                foreach ($markers as $marker) {
                    if (str_contains($code, $marker)) {
                        $violations[] = $file->getFilename().': '.$marker;
                    }
                }
                if ($file->getFilename() !== 'ReviewJobController.php' && str_contains($code, '->verify(')) {
                    $violations[] = $file->getFilename().': ->verify(';
                }
            }
        }
        self::assertSame([], $violations, 'Migrated seams must resolve through hari skills.');
    }

    /** Review reads in Support go through the skill; writers live in Services. */
    public function testSupportReadsReviewsThroughSkill(): void
    {
        $root = dirname(__DIR__, 2).'/app/Support';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $violations = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $code = preg_replace(['~/\\*.*?\\*/~s', '~//[^\n]*~', '~#[^\n]*~'], '', $code);
            if (str_contains($code, 'AutoReviewResult::')) {
                $violations[] = $file->getFilename().': AutoReviewResult::';
            }
        }
        self::assertSame([], $violations, 'Support must read reviews through the skill.');
    }
}
