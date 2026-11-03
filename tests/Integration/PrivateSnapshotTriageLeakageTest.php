<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\PullRequestSnapshot;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class PrivateSnapshotTriageLeakageTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempSnapshotsDir;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();

        $this->tempSnapshotsDir = sys_get_temp_dir().'/proofpatch_triage_sec_'.bin2hex(random_bytes(6));
        @mkdir($this->tempSnapshotsDir, 0755, true);
        putenv('PROOFPATCH_SNAPSHOT_DIR='.$this->tempSnapshotsDir);

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();

        putenv('PROOFPATCH_SNAPSHOT_DIR');
        if (is_dir($this->tempSnapshotsDir)) {
            foreach (glob($this->tempSnapshotsDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempSnapshotsDir);
        }
        $_SESSION = [];
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testUnauthenticatedTriageDoesNotLeakPrivateOrUnknownVisibilitySnapshotTitles(): void
    {
        $store = new PullRequestSnapshot($this->tempSnapshotsDir);

        // 1. Save a private repository snapshot on disk (e.g. from historical run)
        $privatePull = [
            'repository' => 'pratikbhujel/proprietary-repo',
            'number' => 77,
            'title' => 'Top Secret Algorithm Implementation',
            'state' => 'open',
            'headSha' => str_repeat('a', 40),
            'baseSha' => str_repeat('b', 40),
            'user' => 'pratikbhujel',
            'isPrivate' => true,
            'visibility' => 'private',
            'files' => [
                ['name' => 'src/Algorithm.php', 'additions' => 20, 'deletions' => 5],
            ],
        ];
        $privateSnapshot = $store->capture($privatePull);

        // 2. Save a public snapshot on disk
        $publicPull = [
            'repository' => 'pratikbhujel/public-repo',
            'number' => 12,
            'title' => 'Public Open Source Fix',
            'state' => 'open',
            'headSha' => str_repeat('c', 40),
            'baseSha' => str_repeat('d', 40),
            'user' => 'pratikbhujel',
            'isPrivate' => false,
            'visibility' => 'public',
            'files' => [
                ['name' => 'src/Algorithm.php', 'additions' => 10, 'deletions' => 2],
            ],
        ];
        $publicSnapshot = $store->capture($publicPull);

        // 3. Save an unknown visibility snapshot (omitted isPrivate, visibility null)
        $unknownPull = [
            'repository' => 'pratikbhujel/ambiguous-repo',
            'number' => 88,
            'title' => 'Ambiguous Metadata PR',
            'state' => 'open',
            'headSha' => str_repeat('e', 40),
            'baseSha' => str_repeat('f', 40),
            'user' => 'pratikbhujel',
            'files' => [
                ['name' => 'src/Algorithm.php', 'additions' => 5, 'deletions' => 1],
            ],
        ];
        $unknownSnapshot = $store->capture($unknownPull);

        // 4. An unauthenticated visitor requests public overview
        $controller = new DashboardController();
        $overviewResponse = $controller->overview();
        $overviewProps = $overviewResponse->toResponse(Request::create('/'))->getOriginalContent()->getData()['page']['props'];

        $capturedOverview = $overviewProps['recent'] ?? [];
        $overviewTitles = array_map(static fn($c) => $c['pull']['title'] ?? '', $capturedOverview);

        // Must strictly only include public snapshot
        $this->assertContains('Public Open Source Fix', $overviewTitles);
        $this->assertNotContains('Top Secret Algorithm Implementation', $overviewTitles);
        $this->assertNotContains('Ambiguous Metadata PR', $overviewTitles);

        // 5. An unauthenticated visitor views the public snapshot in /repositories
        $session = app('session')->driver('array');
        $repoRequest = Request::create('/repositories?snapshot='.$publicSnapshot['id'], 'GET');
        $repoRequest->setLaravelSession($session);

        $repoResponse = $controller->repositories($repoRequest);
        $repoProps = $repoResponse->toResponse($repoRequest)->getOriginalContent()->getData()['page']['props'];

        $triage = $repoProps['triage'] ?? null;
        $this->assertNotNull($triage, 'Triage should be generated for public PR');

        // Check comparisons in triage output
        $comparisons = $triage['comparisons'] ?? [];
        $triageCandidateTitles = array_map(static fn($c) => $c['candidate']['title'] ?? '', $comparisons);

        // Must NOT leak private or unknown PR title into triage
        $this->assertNotContains('Top Secret Algorithm Implementation', $triageCandidateTitles);
        $this->assertNotContains('Ambiguous Metadata PR', $triageCandidateTitles);
    }

    public function testTriageShowsOnlyLatestPublicCapturePerPullAndPreservesHistory(): void
    {
        $store = new PullRequestSnapshot($this->tempSnapshotsDir);
        $base = ['repository' => 'acme/repo', 'number' => 12, 'title' => 'Target', 'state' => 'open',
            'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40),
            'isPrivate' => false, 'visibility' => 'public',
            'files' => [['name' => 'src/Example.php', 'additions' => 1, 'deletions' => 0]]];
        $target = $store->capture($base);
        $captureAt = function (array $pull, string $time) use ($store): void {
            $capture = $store->capture($pull);
            $path = $this->tempSnapshotsDir.'/'.$capture['id'].'.json';
            $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $document['snapshot']['capturedAt'] = $time;
            $document['sha256'] = hash('sha256', \ProofPatch\Evidence\CanonicalJson::encode($document['snapshot']));
            file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        };
        $candidate = array_replace($base, ['number' => 8, 'title' => 'Old revision', 'headSha' => str_repeat('c', 40)]);
        $captureAt($candidate, '2026-11-01T10:00:00+00:00');
        $candidate = array_replace($candidate, ['title' => 'Current revision', 'headSha' => str_repeat('d', 40)]);
        $captureAt($candidate, '2026-11-01T11:00:00+00:00');
        $captureAt($candidate, '2026-11-01T12:00:00+00:00');
        $captureAt(array_replace($candidate, ['isPrivate' => true, 'visibility' => 'private', 'title' => 'Secret newest revision']), '2026-11-01T13:00:00+00:00');
        $captureAt(array_replace($candidate, ['repository' => 'other/repo', 'title' => 'Other repository']), '2026-11-01T14:00:00+00:00');

        $session = app('session')->driver('array');
        $request = Request::create('/repositories?snapshot='.$target['id'], 'GET');
        $request->setLaravelSession($session);
        $response = (new DashboardController())->repositories($request);
        $props = $response->toResponse($request)->getOriginalContent()->getData()['page']['props'];
        $comparisons = $props['triage']['comparisons'];
        self::assertCount(1, $comparisons);
        self::assertSame(8, $comparisons[0]['candidate']['number']);
        self::assertSame('Current revision', $comparisons[0]['candidate']['title']);
        self::assertSame(str_repeat('d', 40), $comparisons[0]['candidate']['headSha']);
        self::assertCount(6, glob($this->tempSnapshotsDir.'/*.json'), 'Triage must preserve every saved capture.');
    }

    public function testDirectLoadOfPrivateOrUnknownSnapshotFailsClosedWithoutSessionAuthorization(): void
    {
        $store = new PullRequestSnapshot($this->tempSnapshotsDir);

        $privatePull = [
            'repository' => 'pratikbhujel/proprietary-repo',
            'number' => 99,
            'title' => 'Secret Strategy',
            'state' => 'open',
            'headSha' => str_repeat('1', 40),
            'baseSha' => str_repeat('2', 40),
            'user' => 'pratikbhujel',
            'isPrivate' => true,
        ];
        $privateSnapshot = $store->capture($privatePull);

        $controller = new DashboardController();
        $session = app('session')->driver('array');
        $request = Request::create('/repositories?snapshot='.$privateSnapshot['id'], 'GET');
        $request->setLaravelSession($session);

        $response = $controller->repositories($request);
        $props = $response->toResponse($request)->getOriginalContent()->getData()['page']['props'];

        $this->assertNull($props['pull']);
        $this->assertNull($props['snapshot']);
        $this->assertNotNull($props['error']);
        $this->assertStringContainsString('Access denied', $props['error']);
    }

    public function testRevokedOrMismatchedAuthorizationFailsClosed(): void
    {
        $store = new PullRequestSnapshot($this->tempSnapshotsDir);

        $privatePull = [
            'repository' => 'pratikbhujel/secret-repo',
            'number' => 10,
            'title' => 'Secret PR',
            'state' => 'open',
            'headSha' => str_repeat('3', 40),
            'baseSha' => str_repeat('4', 40),
            'user' => 'pratikbhujel',
            'isPrivate' => true,
        ];
        $privateSnapshot = $store->capture($privatePull);

        $controller = new DashboardController();
        $session = app('session')->driver('array');

        // Authorized for a DIFFERENT repo only
        $session->put('github_auth', [
            'token' => 'test_token',
            'repositories' => [
                ['fullName' => 'pratikbhujel/other-repo'],
            ],
        ]);
        $session->save();

        $request = Request::create('/repositories?snapshot='.$privateSnapshot['id'], 'GET');
        $request->setLaravelSession($session);

        $response = $controller->repositories($request);
        $props = $response->toResponse($request)->getOriginalContent()->getData()['page']['props'];

        $this->assertNull($props['pull']);
        $this->assertNotNull($props['error']);
        $this->assertStringContainsString('Access denied', $props['error']);
    }
}
