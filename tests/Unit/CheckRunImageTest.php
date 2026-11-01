<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Evidence\CheckRunImage;
use ProofPatch\Application\Evidence\PublicCheckRunPolicy;

final class CheckRunImageTest extends TestCase
{
    public function testRunIdShape(): void
    {
        self::assertTrue(CheckRunImage::isValidRunId('20261101T103114Z-d959456f5271'));
        self::assertFalse(CheckRunImage::isValidRunId(''));
        self::assertFalse(CheckRunImage::isValidRunId('20261101T103114Z-D959456F5271'));
        self::assertFalse(CheckRunImage::isValidRunId('../artifacts/runs'));
        self::assertFalse(CheckRunImage::isValidRunId("20261101T103114Z-d959456f5271\n"));
    }

    public function testImageTypeAllowlist(): void
    {
        self::assertSame('baseline', CheckRunImage::folderFor('before'));
        self::assertSame('baseline', CheckRunImage::folderFor('baseline'));
        self::assertSame('candidate', CheckRunImage::folderFor('after'));
        self::assertSame('candidate', CheckRunImage::folderFor('candidate'));
        self::assertNull(CheckRunImage::folderFor('negative'));
        self::assertNull(CheckRunImage::folderFor(''));
        self::assertNull(CheckRunImage::folderFor('../candidate'));
    }

    public function testResolvePathServesOnlyContainedFiles(): void
    {
        $root = sys_get_temp_dir().'/proofpatch-image-'.bin2hex(random_bytes(8));
        $runId = '20261101T103114Z-d959456f5271';
        mkdir($root.'/'.$runId.'/candidate', 0700, true);
        file_put_contents($root.'/'.$runId.'/candidate/checkout.png', 'PNG');
        try {
            self::assertSame(
                realpath($root.'/'.$runId.'/candidate/checkout.png'),
                CheckRunImage::resolvePath($root, $runId, 'candidate')
            );
            self::assertNull(CheckRunImage::resolvePath($root, $runId, 'baseline'));
            self::assertNull(CheckRunImage::resolvePath($root.'/missing', $runId, 'candidate'));
            // Traversal in the run id cannot escape: containment still refuses.
            self::assertNull(CheckRunImage::resolvePath($root, '..', 'candidate'));
        } finally {
            unlink($root.'/'.$runId.'/candidate/checkout.png');
            rmdir($root.'/'.$runId.'/candidate');
            rmdir($root.'/'.$runId);
            rmdir($root);
        }
    }

    public function testResolvePathRefusesSymlinkEscape(): void
    {
        $root = sys_get_temp_dir().'/proofpatch-image-'.bin2hex(random_bytes(8));
        $outside = sys_get_temp_dir().'/proofpatch-image-out-'.bin2hex(random_bytes(8));
        $runId = '20261101T103114Z-d959456f5271';
        mkdir($root.'/'.$runId.'/candidate', 0700, true);
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/secret.png', 'PNG');
        symlink($outside.'/secret.png', $root.'/'.$runId.'/candidate/checkout.png');
        try {
            // The link target lives outside the runs base, so it must be refused.
            self::assertNotFalse(realpath($root.'/'.$runId.'/candidate/checkout.png'));
            self::assertNull(CheckRunImage::resolvePath($root, $runId, 'candidate'));
        } finally {
            unlink($root.'/'.$runId.'/candidate/checkout.png');
            unlink($outside.'/secret.png');
            rmdir($root.'/'.$runId.'/candidate');
            rmdir($root.'/'.$runId);
            rmdir($root);
            rmdir($outside);
        }
    }

    public function testPublicCheckRunPolicy(): void
    {
        self::assertTrue(PublicCheckRunPolicy::isPublic(['kind' => 'checkout']));
        self::assertTrue(PublicCheckRunPolicy::isPublic([]));
        self::assertTrue(PublicCheckRunPolicy::isPublic(['kind' => 'owned_backend', 'isSynthetic' => true]));
        self::assertFalse(PublicCheckRunPolicy::isPublic(['kind' => 'owned_backend', 'isSynthetic' => false]));
        self::assertFalse(PublicCheckRunPolicy::isPublic(['kind' => 'owned_backend']));
        self::assertTrue(PublicCheckRunPolicy::isPublic(['context' => [], 'isSynthetic' => true]));
        self::assertFalse(PublicCheckRunPolicy::isPublic(['context' => []]));
    }
}
