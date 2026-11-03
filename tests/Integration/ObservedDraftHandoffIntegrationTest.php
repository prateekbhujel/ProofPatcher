<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PrivateProposalStore;
use ProofPatch\Exceptions\StaleTaskConcurrencyException;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoFetcher;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\ObservedDraftApproval;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use ProofPatch\Models\WorkbenchTask;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use ProofPatch\Verifier\OwnedCaseVerifier;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Covers the isolated-observation handoff Codex wired but left untested. */
final class ObservedDraftHandoffIntegrationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempDir;
    private array $context;
    private array $suggestion;
    private string $candidateSource;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        $this->tempDir = sys_get_temp_dir().'/pp-handoff-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        putenv('PROOFPATCH_PRIVATE_PROPOSALS_DIR='.$this->tempDir.'/proposals');
        $this->candidateSource = "<?php function orderTotal(array \$items): int { return 0; }";
        $this->context = ['repository' => DedicatedTestRepo::requireName(), 'issueNumber' => 1,
            'commit' => DedicatedTestRepo::requireBaseCommit(), 'path' => DedicatedTestRepoFetcher::SOURCE_PATH,
            'sourceSha256' => DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256,
            'title' => 'Zero-quantity items inflate the order total',
            'body' => 'When an order contains items with quantity 0, OrderTotal calculates with quantity 1.'];
        $this->suggestion = ['candidate' => ['source' => $this->candidateSource,
            'sha256' => hash('sha256', $this->candidateSource)],
            'privateProvenanceRecorded' => true, 'privateProvenanceId' => str_repeat('a', 32),
            'model' => 'muse', 'requestDigest' => str_repeat('b', 64)];
        WorkbenchTask::query()->delete();
    }

    protected function tearDown(): void
    {
        putenv('PROOFPATCH_PRIVATE_PROPOSALS_DIR');
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function passingObservation(): array
    {
        return ['status' => 'passed', 'scope' => 'finite_local_fixture', 'provider' => 'modal',
            'candidateSha256' => hash('sha256', $this->candidateSource),
            'baseline' => 'fixture_failed', 'negative' => 'fixture_failed', 'candidate' => 'fixture_passed',
            'calls' => ['baseline' => 3, 'negative' => 3, 'candidate' => 3]];
    }

    private function taskWithProposal(): WorkbenchTask
    {
        $source = (string) file_get_contents(base_path('tests/Fixtures/owned-review-case/src/OrderTotal.php'));
        $task = WorkbenchTask::recordIntake($this->context + ['source' => $source,
            'sourceSha256' => DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256], false);
        $task->recordProposal($this->suggestion + ['candidate' => $this->suggestion['candidate']]);
        return $task;
    }

    public function testFixtureObservationIsStoredSeparatelyAndRejectsStaleWrites(): void
    {
        $task = $this->taskWithProposal();
        $version = (int) $task->version;
        $task->recordFixtureObservation($this->passingObservation(), 'fixture-'.str_repeat('c', 24), $version, hash('sha256', $this->candidateSource));
        self::assertSame('OBSERVED_PASS', $task->verification_decision);
        self::assertSame('needs_review', $task->status);
        self::assertFalse($task->is_simulated);
        self::assertSame('fixture-'.str_repeat('c', 24), $task->verification_report_id);

        $this->expectException(StaleTaskConcurrencyException::class);
        $task->recordFixtureObservation($this->passingObservation(), 'fixture-'.str_repeat('d', 24), $version, hash('sha256', $this->candidateSource));
    }

    public function testSimulatedObservationCannotBeRecordedAsObservedPass(): void
    {
        $task = $this->taskWithProposal();
        $observation = $this->passingObservation();
        $observation['provider'] = 'simulated';
        $this->expectException(\InvalidArgumentException::class);
        $task->recordFixtureObservation($observation, 'fixture-'.str_repeat('c', 24), (int) $task->version, hash('sha256', $this->candidateSource));
    }

    public function testIdenticalExistingDraftIsReusedWithoutAnyGitHubWrite(): void
    {
        PrivateProposalStore::forRuntime()->record(
            ['githubUserId' => 7, 'repository' => DedicatedTestRepo::name(), 'issueNumber' => 1,
                'baseCommit' => DedicatedTestRepo::requireBaseCommit(), 'path' => DedicatedTestRepoFetcher::SOURCE_PATH,
                'sourceSha256' => DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256,
                'candidateSha256' => hash('sha256', $this->candidateSource)],
            ['userId' => 7, 'repositories' => [strtolower((string) DedicatedTestRepo::name()) => true]],
            ['candidateSource' => $this->candidateSource, 'model' => 'muse',
                'requestDigest' => str_repeat('b', 64), 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]],
            str_repeat('a', 32));

        $approvals = new ObservedDraftApproval($this->tempDir.'/observed', str_repeat('s', 32));
        $observation = $this->passingObservation() + ['harnessSha256' => OwnedCaseVerifier::KNOWN_TEST_SHA256,
            'baselineSha256' => DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256];
        $token = $approvals->issue(7, $this->context, $this->suggestion, $observation);

        $writes = 0;
        $repo = DedicatedTestRepo::requireName();
        $client = new MockHttpClient(function (string $method, string $url) use (&$writes, $repo): MockResponse {
            if ($method !== 'GET') $writes++;
            return match (true) {
                str_ends_with($url, '/user') => new MockResponse((string) json_encode(['id' => 7, 'login' => explode('/', $repo)[0]])),
                str_contains($url, '/pulls/14/files') => new MockResponse((string) json_encode([['filename' => 'src/OrderTotal.php', 'status' => 'modified']])),
                str_contains($url, '/pulls?') => new MockResponse((string) json_encode([['number' => 14, 'draft' => true,
                    'html_url' => 'https://github.com/'.$repo.'/pull/14',
                    'base' => ['ref' => 'main', 'sha' => DedicatedTestRepo::requireBaseCommit()],
                    'head' => ['ref' => 'proofpatch/order-total-14', 'sha' => str_repeat('e', 40), 'repo' => ['full_name' => $repo]]]])),
                str_contains($url, '/contents/') => new MockResponse((string) json_encode(['encoding' => 'base64',
                    'content' => base64_encode($this->candidateSource)])),
                str_ends_with($url, '/issues/1') => new MockResponse((string) json_encode(['number' => 1, 'state' => 'open',
                    'title' => $this->context['title'], 'body' => $this->context['body']])),
                default => new MockResponse((string) json_encode(['private' => true, 'full_name' => $repo, 'default_branch' => 'main'])),
            };
        });

        $publisher = new DedicatedTestRepoPublisher(
            new ServerHeldPublicationApproval($this->tempDir.'/pub', 'test-secret-key-32-bytes-minimum!!'),
            $client, disconnected: false);
        $result = $publisher->publishObservedDraft($this->context, $this->suggestion, 7, $token, 'gh-token', $approvals);

        self::assertSame('existing', $result['status']);
        self::assertSame(14, $result['prNumber']);
        self::assertSame(0, $writes, 'An identical draft must be reused, never rewritten.');
        $this->expectException(\RuntimeException::class);
        $approvals->consume($token, 7, $this->context, $this->suggestion);
    }

    public function testObservedPublishFailsClosedWithoutConnection(): void
    {
        $approvals = new ObservedDraftApproval($this->tempDir.'/observed2', str_repeat('s', 32));
        $publisher = new DedicatedTestRepoPublisher(
            new ServerHeldPublicationApproval($this->tempDir.'/pub2', 'test-secret-key-32-bytes-minimum!!'),
            new MockHttpClient(), disconnected: true);
        $this->expectException(\RuntimeException::class);
        $publisher->publishObservedDraft($this->context, $this->suggestion, 7, 'x', 'gh-token', $approvals);
    }
}
