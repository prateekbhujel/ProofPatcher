<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use ProofPatch\Http\Controllers\KernelController;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class KernelControllerTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function propsFor(array $sessionData): array
    {
        $session = new Store('test', new ArraySessionHandler(10));
        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }
        $request = Request::create('/', 'GET');
        $request->setLaravelSession($session);

        return (new KernelController())->show($request)
            ->toResponse($request)->getOriginalContent()->getData()['page']['props'];
    }

    private function auth(bool $connected = true, array $repos = [['fullName' => 'acme/repo', 'isPrivate' => false]]): array
    {
        if (!$connected) {
            return [];
        }

        return [
            'github_auth' => [
                'token' => '[REDACTED]',
                'user' => ['id' => 1, 'login' => 'maintainer'],
                'repositories' => $repos,
            ],
        ];
    }

    public function testDisconnectedVisitorIsToldToConnect(): void
    {
        $props = $this->propsFor([]);

        $this->assertFalse($props['connected']);
        $this->assertSame('connect', $props['nextStep']['key']);
        $this->assertSame('/issues', $props['nextStep']['href']);
        $this->assertSame(['intake', 'propose', 'verify', 'publish'], array_column($props['stages'], 'key'));
        $this->assertSame('todo', $props['stages'][0]['state']);
        $this->assertSame([], $props['recentFindings']);
        $this->assertNull($props['lastVerification']);
        $this->assertArrayHasKey('label', $props['provider']);
        $this->assertArrayHasKey('model', $props['provider']);
    }

    public function testConnectedWithoutRepositoriesAsksForGrant(): void
    {
        $props = $this->propsFor($this->auth(true, []));

        $this->assertTrue($props['connected']);
        $this->assertSame('grant', $props['nextStep']['key']);
        $this->assertSame('/settings', $props['nextStep']['href']);
    }

    public function testConnectedWithoutSelectionAsksToPickARepository(): void
    {
        $props = $this->propsFor($this->auth());

        $this->assertSame('select_repo', $props['nextStep']['key']);
        $this->assertSame('active', $props['stages'][0]['state']);
        $this->assertSame(1, $props['reposCount']);
    }

    public function testIssueFlowAdvancesProposeVerifyPublishDone(): void
    {
        $base = $this->auth() + ['selected_github_repo' => 'acme/repo'];

        $target = $this->propsFor($base);
        $this->assertSame('target', $target['nextStep']['key']);

        $propose = $this->propsFor($base + ['issue_context' => ['repository' => 'acme/repo', 'issueNumber' => 1]]);
        $this->assertSame('propose', $propose['nextStep']['key']);
        $this->assertSame('done', $propose['stages'][0]['state']);
        $this->assertSame('active', $propose['stages'][1]['state']);

        $verify = $this->propsFor($base + [
            'issue_context' => ['repository' => 'acme/repo', 'issueNumber' => 1],
            'issue_suggestion' => ['path' => 'src/Fix.php'],
        ]);
        $this->assertSame('verify', $verify['nextStep']['key']);
        $this->assertSame('done', $verify['stages'][1]['state']);
        $this->assertSame('active', $verify['stages'][2]['state']);

        $report = [
            'id' => '20261031T100000Z-abcdef123456',
            'decision' => 'VERIFIED',
            'isSimulated' => false,
            'isolated' => true,
            'genuineProviderEvidence' => true,
            'oracleVerified' => true,
            'gates' => ['baselineReproduced' => true, 'negativeControlCaught' => true, 'candidatePassed' => true,
                'candidateStaticSafetyPassed' => true, 'allowlistIntegrityIntact' => true],
            'receipt' => ['sha256' => str_repeat('d', 64)],
            'context' => ['repository' => 'acme/repo'],
        ];
        $publish = $this->propsFor($base + [
            'issue_context' => ['repository' => 'acme/repo', 'issueNumber' => 1],
            'issue_suggestion' => ['path' => 'src/Fix.php'],
            'issue_verification_report' => $report,
            'issue_is_dedicated_test_repo' => true,
        ]);
        $this->assertSame('publish', $publish['nextStep']['key']);
        $this->assertSame('done', $publish['stages'][2]['state']);
        $this->assertSame('active', $publish['stages'][3]['state']);
        $this->assertSame('VERIFIED', $publish['lastVerification']['decision']);
        $this->assertSame(str_repeat('d', 64), $publish['lastVerification']['receiptSha256']);

        $failedGate = $report;
        $failedGate['gates']['candidatePassed'] = false;
        $failed = $this->propsFor($base + [
            'issue_context' => ['repository' => 'acme/repo', 'issueNumber' => 1],
            'issue_suggestion' => ['path' => 'src/Fix.php'],
            'issue_verification_report' => $failedGate,
            'issue_is_dedicated_test_repo' => true,
        ]);
        $this->assertSame('verify', $failed['nextStep']['key']);
        $this->assertNotSame('done', $failed['stages'][2]['state']);

        // A simulated run must never become a publish step.
        $simulated = $this->propsFor($base + [
            'issue_context' => ['repository' => 'acme/repo', 'issueNumber' => 1],
            'issue_suggestion' => ['path' => 'src/Fix.php'],
            'issue_verification_report' => array_merge($report, ['isSimulated' => true]),
            'issue_is_dedicated_test_repo' => true,
        ]);
        $this->assertNotSame('publish', $simulated['nextStep']['key']);

        $done = $this->propsFor($base + [
            'issue_published_pr' => ['prNumber' => 7, 'prUrl' => 'https://github.com/acme/repo/pull/7', 'repository' => 'acme/repo'],
        ]);
        $this->assertSame('done', $done['nextStep']['key']);
        $this->assertSame('https://github.com/acme/repo/pull/7', $done['nextStep']['href']);
        $this->assertSame('done', $done['stages'][3]['state']);
    }

    public function testActiveWorkOutranksConnectWhenDisconnected(): void
    {
        $props = $this->propsFor([
            'issue_context' => ['repository' => 'synthetic-fixture/order-total', 'issueNumber' => 101],
        ]);

        $this->assertFalse($props['connected']);
        $this->assertSame('propose', $props['nextStep']['key']);
        $this->assertSame('done', $props['stages'][0]['state']);
        $this->assertSame('Target loaded.', $props['stages'][0]['detail']);
    }

    public function testCapturedPullRequestWithoutReviewSuggestsReview(): void
    {
        $props = $this->propsFor($this->auth() + [
            'selected_github_repo' => 'acme/repo',
            'pullRequest' => ['repository' => 'acme/repo', 'number' => 3],
        ]);

        $this->assertSame('review', $props['nextStep']['key']);
        $this->assertSame('/repositories', $props['nextStep']['href']);
    }

    public function testRecentFindingsAreScopedToTheGrant(): void
    {
        foreach (['acme/repo' => 'Own finding', 'other/foreign' => 'Foreign finding'] as $repo => $summary) {
            AutoReviewResult::create([
                'repository' => $repo,
                'pull_number' => 1,
                'head_sha' => str_repeat('1', 40),
                'base_sha' => str_repeat('2', 40),
                'status' => 'completed',
                'is_public' => true,
                'summary' => $summary,
                'published_status' => 'local_only',
            ]);
        }

        $props = $this->propsFor($this->auth() + ['selected_github_repo' => 'acme/repo']);

        $this->assertCount(1, $props['recentFindings']);
        $this->assertSame('acme/repo', $props['recentFindings'][0]['repository']);
        $this->assertSame(1, $props['autoStats']['totalReviews']);
    }

    public function testRecentFindingsKeepOneRowPerPullRequest(): void
    {
        foreach ([[7, 'First review', '1'], [7, 'Second review', '3'], [8, 'Other pull', '1']] as [$pull, $summary, $shaChar]) {
            AutoReviewResult::create([
                'repository' => 'acme/repo',
                'pull_number' => $pull,
                'head_sha' => str_repeat($shaChar, 40),
                'base_sha' => str_repeat('2', 40),
                'status' => 'completed',
                'is_public' => true,
                'summary' => $summary,
                'published_status' => 'local_only',
            ]);
        }

        $props = $this->propsFor($this->auth() + ['selected_github_repo' => 'acme/repo']);

        $this->assertCount(2, $props['recentFindings']);
        $this->assertSame([8, 7], array_map(static fn ($row) => $row['pull_number'], $props['recentFindings']));
    }
}
