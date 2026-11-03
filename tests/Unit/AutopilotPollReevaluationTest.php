<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\PullRequestActions;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\MaintainerAutopilotSetting;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AutopilotPollReevaluationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private const REPO = 'example-owner/demo';
    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private string $checkStatus = 'pending';
    private bool $installed = true;
    private bool $actionReadAllowed = true;
    private bool $optOutDuringReadiness = false;
    private bool $reviewOffDuringReadiness = false;
    private int $modelCalls = 0;
    private array $mergeRequests = [];

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        RepositoryAutoReviewSetting::setRepoEnabled(self::REPO, true);
        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', true);
        AutoReviewResult::create([
            'repository' => self::REPO, 'pull_number' => 1, 'head_sha' => self::HEAD,
            'base_sha' => self::BASE, 'status' => 'completed', 'is_public' => true,
            'recommendation' => 'approve', 'published_status' => 'local_only',
        ]);
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testPendingChecksAreReevaluatedAndGreenIsMergedOnceWithoutAnotherModelCall(): void
    {
        $service = $this->service();
        $first = $service->poll('local-synthetic-token');
        self::assertSame('blocked', $first['details'][0]['autoMerge']['status'], json_encode($first));
        self::assertSame([], $this->mergeRequests);

        $this->checkStatus = 'success';
        $second = $service->poll('local-synthetic-token');
        self::assertSame('merged', $second['details'][0]['autoMerge']['status'], json_encode($second));
        self::assertCount(1, $this->mergeRequests);
        self::assertSame(self::HEAD, $this->mergeRequests[0]['sha']);

        $third = $service->poll('local-synthetic-token');
        self::assertSame('already_attempted', $third['details'][0]['autoMerge']['status'], json_encode($third));
        self::assertCount(1, $this->mergeRequests);
        self::assertSame(0, $this->modelCalls);
    }

    public function testDisabledMergeAndRevokedInstallationProduceNoWrite(): void
    {
        $this->checkStatus = 'success';
        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', false);
        $service = $this->service();
        $off = $service->poll('local-synthetic-token');
        self::assertArrayNotHasKey('autoMerge', $off['details'][0]);
        self::assertSame([], $this->mergeRequests);

        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', true);
        $this->installed = false;
        $revoked = $service->poll('local-synthetic-token');
        self::assertSame('skipped_unauthorized', $revoked['details'][0]['action'], json_encode($revoked));
        self::assertSame([], $this->mergeRequests);
        self::assertSame(0, $this->modelCalls);
    }

    public function testOptOutWhileReadingReadinessStopsMerge(): void
    {
        $this->checkStatus = 'success';
        $this->optOutDuringReadiness = true;
        $result = $this->service()->poll('local-synthetic-token');
        self::assertSame('blocked', $result['details'][0]['autoMerge']['status'], json_encode($result));
        self::assertSame([], $this->mergeRequests);
    }

    public function testRevocationAtActionReadNeverSendsMergeRequest(): void
    {
        $this->checkStatus = 'success';
        $this->actionReadAllowed = false;
        $result = $this->service()->poll('local-synthetic-token');
        self::assertSame('unknown', $result['details'][0]['autoMerge']['status'], json_encode($result));
        self::assertSame([], $this->mergeRequests);
    }

    public function testDisablingRepositoryReviewWhileReadinessLoadsStopsMerge(): void
    {
        $this->checkStatus = 'success';
        $this->reviewOffDuringReadiness = true;
        $result = $this->service()->poll('local-synthetic-token');
        self::assertSame('blocked', $result['details'][0]['autoMerge']['status'], json_encode($result));
        self::assertSame([], $this->mergeRequests);
    }

    private function service(): AutoReviewService
    {
        $githubHttp = new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '/user') return new MockResponse(json_encode(['id' => 7, 'login' => 'example-owner']));
            if ($path === '/user/installations') return new MockResponse(json_encode(['installations' => [['id' => 101]]]));
            if ($path === '/user/installations/101/repositories') return new MockResponse(json_encode(['repositories' => $this->installed ? [[
                'id' => 1, 'name' => 'demo', 'full_name' => self::REPO, 'owner' => ['login' => 'example-owner'],
                'private' => false, 'visibility' => 'public',
            ]] : []]));
            if ($path === '/repos/'.self::REPO.'/issues') return new MockResponse('[]');
            if ($path === '/repos/'.self::REPO.'/branches/main/protection') return new MockResponse(json_encode([
                'required_status_checks' => ['strict' => true, 'contexts' => ['ci']],
                'enforce_admins' => ['enabled' => true], 'required_conversation_resolution' => ['enabled' => true],
            ]));
            if ($path === '/repos/'.self::REPO.'/pulls') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                if (($query['state'] ?? '') === 'closed') return new MockResponse('[]');
                return new MockResponse(json_encode([['number' => 1, 'title' => 'Fix issue', 'state' => 'open',
                    'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::BASE, 'repo' => ['private' => false, 'visibility' => 'public']]]]));
            }
            if (str_ends_with($path, '/check-runs')) {
                if ($this->optOutDuringReadiness) MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', false);
                if ($this->reviewOffDuringReadiness) RepositoryAutoReviewSetting::setRepoEnabled(self::REPO, false);
                return new MockResponse(json_encode(['total_count' => 1, 'check_runs' => [[
                    'id' => 1, 'status' => $this->checkStatus === 'pending' ? 'in_progress' : 'completed',
                    'conclusion' => $this->checkStatus === 'pending' ? null : $this->checkStatus,
                ]]]));
            }
            if (str_ends_with($path, '/status')) return new MockResponse(json_encode(['total_count' => 0, 'state' => 'pending']));
            if ($path === '/graphql') return new MockResponse(json_encode(['data' => ['repository' => ['pullRequest' => [
                'reviewThreads' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => []],
            ]]]]));
            if ($path === '/repos/'.self::REPO.'/pulls/1') return new MockResponse(json_encode($this->pull()));
            throw new \RuntimeException('Unexpected GitHub request: '.$path);
        });
        $actionsHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($method === 'PUT') {
                $this->mergeRequests[] = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
                return new MockResponse(json_encode(['merged' => true, 'sha' => str_repeat('c', 40)]));
            }
            if (!$this->actionReadAllowed) return new MockResponse('{}', ['http_code' => 403]);
            return new MockResponse(json_encode($this->pull()));
        });
        $modelHttp = new MockHttpClient(function (): MockResponse {
            $this->modelCalls++;
            throw new \RuntimeException('Unexpected model call');
        });
        $budget = new RequestBudget(sys_get_temp_dir().'/proofpatch-poll-test-'.bin2hex(random_bytes(6)), 500000, 10);
        return new AutoReviewService(github: new GitHubAppClient($githubHttp),
            reviewAgent: new PullRequestReview($modelHttp, $budget), budget: $budget,
            basetenKey: 'synthetic-not-a-secret', pullActions: new PullRequestActions($actionsHttp), autoMergeReady: true);
    }

    private function pull(): array
    {
        return ['state' => 'open', 'merged' => false, 'draft' => false, 'mergeable' => true,
            'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::BASE, 'ref' => 'main']];
    }
}
