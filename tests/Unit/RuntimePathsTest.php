<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Support\RuntimePaths;

final class RuntimePathsTest extends TestCase
{
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['HOME', 'PROOFPATCH_SNAPSHOT_DIR', 'PROOFPATCH_APPROVALS_DIR'] as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }

    public function testDefaultsStayInPrivateStorageWithoutLaravelBoot(): void
    {
        self::assertSame('/project/storage/app/private/proofpatch/runs', RuntimePaths::runs('/project'));
        self::assertSame('/project/storage/app/private/proofpatch/pull-requests', RuntimePaths::snapshots('/project'));
        self::assertSame('/project/storage/app/private/proofpatch/approvals', RuntimePaths::approvals('/project'));
    }

    public function testExplicitStorageLocationsAndExistingHomeApprovalStoreArePreserved(): void
    {
        putenv('HOME=/operator');
        self::assertSame('/operator/.local/share/proofpatch/runtime/approvals', RuntimePaths::approvals('/project'));
        putenv('PROOFPATCH_SNAPSHOT_DIR=/private/snapshots');
        putenv('PROOFPATCH_APPROVALS_DIR=/private/approvals');
        self::assertSame('/private/snapshots', RuntimePaths::snapshots('/project'));
        self::assertSame('/private/approvals', RuntimePaths::approvals('/project'));
    }
}
