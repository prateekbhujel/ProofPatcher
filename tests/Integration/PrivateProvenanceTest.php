<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PrivateProposalStore;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\Evidence\Receipt;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use ProofPatch\Http\Controllers\IssueWorkbenchController;
use ProofPatch\Models\WorkbenchTask;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Private provenance end to end: propose records owner-scoped provenance,
 * lookup fails closed on revoked access or changed bytes, and publication
 * accepts a matching record but refuses without one.
 */
final class PrivateProvenanceTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $dir;
    private string $proposalsDir;
    private string|false $previousKey;
    private string|false $previousProposalsDir;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        config(['session.driver' => 'array']);
        $this->dir = sys_get_temp_dir() . '/private-prov-test-' . bin2hex(random_bytes(6));
        $this->proposalsDir = $this->dir . '/proposals';
        mkdir($this->dir, 0700, true);
        $this->previousKey = getenv('BASETEN_API_KEY');
        putenv('BASETEN_API_KEY=unit-test-baseten-key-123456');
        $this->previousProposalsDir = getenv('PROOFPATCH_PRIVATE_PROPOSALS_DIR');
        putenv('PROOFPATCH_PRIVATE_PROPOSALS_DIR=' . $this->proposalsDir);
    }

    protected function tearDown(): void
    {
        $this->previousKey === false ? putenv('BASETEN_API_KEY') : putenv('BASETEN_API_KEY=' . $this->previousKey);
        $this->previousProposalsDir === false
            ? putenv('PROOFPATCH_PRIVATE_PROPOSALS_DIR')
            : putenv('PROOFPATCH_PRIVATE_PROPOSALS_DIR=' . $this->previousProposalsDir);
        $this->teardownIsolatedDatabase();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function context(): array
    {
        $source = file_get_contents(base_path('tests/Fixtures/owned-review-case/src/OrderTotal.php'));
        $test = file_get_contents(base_path('tests/Fixtures/owned-review-case/tests/order_total.php'));
        return ['repository' => DedicatedTestRepo::requireName(), 'issueNumber' => 1,
            'issueUrl' => 'https://github.com/' . DedicatedTestRepo::requireName() . '/issues/1',
            'commit' => DedicatedTestRepo::requireBaseCommit(), 'title' => 'Zero-quantity order bug',
            'body' => 'The total is wrong when quantity is zero.', 'path' => 'src/OrderTotal.php',
            'source' => $source, 'sourceSha256' => hash('sha256', $source),
            'testSource' => $test, 'testSha256' => hash('sha256', $test)];
    }

    private function controller(): IssueWorkbenchController
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $ctx = $this->context();
            if ($method === 'GET' && str_ends_with($url, '/issues/1')) {
                return new MockResponse(json_encode(['number' => 1, 'state' => 'open', 'title' => $ctx['title'], 'body' => $ctx['body']]));
            }
            if ($method === 'GET' && str_contains($url, '/contents/src/OrderTotal.php')) {
                return new MockResponse(json_encode(['content' => base64_encode($ctx['source'])]));
            }
            if ($method === 'GET' && str_contains($url, '/contents/tests/order_total.php')) {
                return new MockResponse(json_encode(['content' => base64_encode($ctx['testSource'])]));
            }
            if ($method === 'POST' && str_starts_with($url, 'https://inference.baseten.co/')) {
                $content = json_encode(['path' => $ctx['path'], 'find' => "max(1, \$item['quantity'])",
                    'replace' => "\$item['quantity']", 'explanation' => 'Use actual quantity.']);
                return new MockResponse(json_encode(['model' => 'meta-models/muse-spark-open-1026',
                    'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30]]));
            }
            throw new \RuntimeException('Unexpected network request: ' . $method . ' ' . $url);
        });
        return new IssueWorkbenchController($http, new RequestBudget($this->dir . '/budget', 500000, 10), null, new SingleUseReviewApproval($this->dir . '/approvals'));
    }

    /** Runs propose with consent and returns [suggestion, context]. */
    private function proposeWithConsent(): array
    {
        $ctx = $this->context();
        $controller = $this->controller();
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'mock-github-token', 'user' => ['id' => 7], 'repositories' => [
            ['fullName' => $ctx['repository'], 'isPrivate' => true],
        ]]);
        $task = WorkbenchTask::recordIntake($ctx, true, githubUserId: 7);
        $session->put('issue_task_id', $task->id);
        $session->put('issue_context', $ctx);
        $session->put('issue_is_dedicated_test_repo', true);
        $session->put('issue_is_owned_fixture', true);
        $access = ['userId' => 7, 'repositories' => [strtolower($ctx['repository']) => true]];
        $show = Request::create('/issues', 'GET');
        $show->setLaravelSession($session);
        $show->attributes->set('proofpatch_github_access', $access);
        $preview = $controller->show($show)->toResponse($show)->getOriginalContent()->getData()['page']['props']['preview'];
        $request = Request::create('/issues/propose', 'POST', ['digest' => $preview['digest'], 'approved' => true,
            'approval_token' => $preview['approvalToken'], 'private_consent' => true]);
        $request->setLaravelSession($session);
        $request->attributes->set('proofpatch_github_access', $access);
        $controller->propose($request);
        $suggestion = $session->get('issue_suggestion');
        self::assertIsArray($suggestion, 'propose failed: ' . (string) $session->get('error'));
        return [$suggestion, $ctx];
    }

    public function testProposeRecordsPrivateProvenanceBoundToVerifiedIdentity(): void
    {
        [$suggestion, $ctx] = $this->proposeWithConsent();
        self::assertTrue($suggestion['privateProvenanceRecorded'] ?? false);
        $files = glob($this->proposalsDir . '/*.enc');
        self::assertCount(1, $files);

        $store = PrivateProposalStore::forRuntime();
        $binding = ['githubUserId' => 7, 'repository' => $ctx['repository'], 'issueNumber' => 1,
            'baseCommit' => $ctx['commit'], 'path' => $ctx['path'],
            'sourceSha256' => $ctx['sourceSha256'], 'candidateSha256' => $suggestion['candidate']['sha256']];
        $grant = ['userId' => 7, 'repositories' => [strtolower($ctx['repository']) => true]];
        $record = $store->lookup($binding, $grant, $suggestion['privateProvenanceId']);
        self::assertNotNull($record);
        self::assertSame('meta-models/muse-spark-open-1026', $record['model']);
        self::assertSame($suggestion['candidate']['source'], $record['candidateSource']);

        $task = WorkbenchTask::where('repository', $ctx['repository'])->where('issue_number', 1)->firstOrFail();
        $session = app('session')->driver('array');
        $session->forget('issue_suggestion');
        $request = Request::create('/issues', 'GET');
        $request->setLaravelSession($session);
        \ProofPatch\Support\TaskWorkflowHelper::hydrateTaskSession($request, $task);
        $restored = $session->get('issue_suggestion');
        self::assertSame($suggestion['privateProvenanceId'], $restored['privateProvenanceId']);
        self::assertSame($suggestion['requestDigest'], $restored['requestDigest']);
        self::assertTrue($restored['privateProvenanceRecorded']);

        // Revoked access or a changed candidate yields nothing.
        self::assertNull($store->lookup($binding, ['userId' => 7, 'repositories' => []], $suggestion['privateProvenanceId']));
        $changed = $binding;
        $changed['candidateSha256'] = hash('sha256', 'other');
        self::assertNull($store->lookup($changed, $grant, $suggestion['privateProvenanceId']));
    }

    private function evidenceFor(string $candidateSource, string $candidateSha): array
    {
        $runDir = $this->dir . '/runs/run-priv';
        mkdir($runDir . '/baseline', 0700, true);
        mkdir($runDir . '/negative', 0700, true);
        mkdir($runDir . '/candidate', 0700, true);
        $baseSource = (string) file_get_contents(base_path('tests/Fixtures/owned-review-case/src/OrderTotal.php'));
        $testSource = (string) file_get_contents(base_path('tests/Fixtures/owned-review-case/tests/order_total.php'));
        file_put_contents($runDir . '/patch.diff', "--- a/src/OrderTotal.php\n+++ b/src/OrderTotal.php\n");
        file_put_contents($runDir . '/baseline/run.log', "zero quantity: expected 250, got 750\n");
        file_put_contents($runDir . '/negative/run.log', "multiple items: expected 330, got 0\n");
        file_put_contents($runDir . '/candidate/run.log', "All order total cases passed.\n");
        $report = ['id' => '20261101T120000Z-' . substr(hash('sha256', $candidateSha), 0, 12), 'kind' => 'owned_backend', 'decision' => 'VERIFIED',
            'isolated' => true, 'oracleVerified' => true, 'genuineProviderEvidence' => true,
            'baseCommit' => DedicatedTestRepo::requireBaseCommit(), 'targetFile' => 'src/OrderTotal.php',
            'baseSha256' => hash('sha256', $baseSource), 'candidateSha256' => $candidateSha,
            'testSha256' => hash('sha256', $testSource), 'patchSha256' => hash_file('sha256', $runDir . '/patch.diff'),
            'gates' => ['baselineReproduced' => true, 'negativeControlCaught' => true, 'candidatePassed' => true,
                'candidateStaticSafetyPassed' => true, 'allowlistIntegrityIntact' => true],
            'runs' => ['baseline' => ['exitCode' => 1, 'logSha256' => hash_file('sha256', $runDir . '/baseline/run.log')],
                'negative' => ['exitCode' => 1, 'logSha256' => hash_file('sha256', $runDir . '/negative/run.log')],
                'candidate' => ['exitCode' => 0, 'logSha256' => hash_file('sha256', $runDir . '/candidate/run.log')]],
            'evidenceDirectory' => $runDir];
        file_put_contents($runDir . '/result.json', json_encode($report, JSON_PRETTY_PRINT));
        $receipt = (new Receipt())->create($runDir);
        file_put_contents($runDir . '/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT));
        return [$runDir, $report, $receipt];
    }

    public function testPublishAcceptsMatchingPrivateProvenance(): void
    {
        [$suggestion, $ctx] = $this->proposeWithConsent();
        $candidateSource = $suggestion['candidate']['source'];
        $candidateSha = $suggestion['candidate']['sha256'];
        [$runDir, $report, $receipt] = $this->evidenceFor($candidateSource, $candidateSha);

        $approvalStore = new ServerHeldPublicationApproval($this->dir . '/pub-approvals');
        $authToken = $approvalStore->issue($report['id'], $runDir, $receipt['sha256'],
            DedicatedTestRepo::requireBaseCommit(), $ctx['repository'], 1, 'src/OrderTotal.php',
            $report['baseSha256'], $candidateSha, $report['testSha256'], isSimulated: false,
            privateProvenanceId: $suggestion['privateProvenanceId']);

        $repo = DedicatedTestRepo::requireName();
        $baseCommit = DedicatedTestRepo::requireBaseCommit();
        $requests = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $repo, $baseCommit): MockResponse {
            $requests[] = $method . ' ' . $url;
            if ($url === 'https://api.github.com/user') {
                return new MockResponse(json_encode(['id' => 7, 'login' => 'owner']));
            }
            if ($url === 'https://api.github.com/repos/' . $repo) {
                return new MockResponse(json_encode(['full_name' => $repo]));
            }
            if (str_ends_with($url, '/git/ref/heads/main')) {
                return new MockResponse(json_encode(['object' => ['sha' => $baseCommit]]));
            }
            if (str_contains($url, '/git/commits/' . $baseCommit)) {
                return new MockResponse(json_encode(['tree' => ['sha' => str_repeat('1', 40)]]));
            }
            if (str_ends_with($url, '/git/blobs')) {
                return new MockResponse(json_encode(['sha' => str_repeat('2', 40)]));
            }
            if (str_ends_with($url, '/git/trees')) {
                return new MockResponse(json_encode(['sha' => str_repeat('3', 40)]));
            }
            if (str_ends_with($url, '/git/commits')) {
                return new MockResponse(json_encode(['sha' => str_repeat('4', 40)]));
            }
            if (str_ends_with($url, '/git/refs')) {
                return new MockResponse(json_encode(['ref' => 'refs/heads/proofpatch/1-x']));
            }
            if (str_ends_with($url, '/pulls')) {
                $payload = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
                self::assertTrue($payload['draft']);
                self::assertStringContainsString('private test issue and selected source file', $payload['body']);
                self::assertStringNotContainsString('public fixture context', $payload['body']);
                return new MockResponse(json_encode([
                    'number' => 9, 'html_url' => 'https://github.com/' . $repo . '/pull/9', 'draft' => true]));
            }
            throw new \RuntimeException('Unexpected: ' . $method . ' ' . $url);
        });

        $publisher = new DedicatedTestRepoPublisher($approvalStore, $mock, disconnected: false);
        $result = $publisher->publishDraftPr(
            ['repository' => $repo, 'issueNumber' => 1, 'path' => 'src/OrderTotal.php',
                'proposalOrigin' => 'muse', 'model' => 'meta-models/muse-spark-open-1026', 'isPrivateDedicated' => true,
                'privateProvenanceId' => $suggestion['privateProvenanceId'], 'requestDigest' => $suggestion['requestDigest']],
            $runDir, $candidateSource, $authToken, 'mock-token-1234567890'
        );
        self::assertSame('published', $result['status']);
        self::assertSame(9, $result['prNumber']);
        self::assertTrue($result['draft']);
        self::assertSame('GET https://api.github.com/user', $requests[0]);
        self::assertSame('GET https://api.github.com/repos/' . $repo, $requests[1]);
    }

    public function testPrivateClaimDoesNotFallBackToPublicRecord(): void
    {
        [$suggestion, $ctx] = $this->proposeWithConsent();
        $candidateSource = $suggestion['candidate']['source'];
        $candidateSha = $suggestion['candidate']['sha256'];
        foreach (glob($this->proposalsDir . '/*.enc') ?: [] as $file) {
            @unlink($file);
        }
        // A public record for the same bytes must not satisfy a private claim.
        (new \ProofPatch\Agent\ServerHeldModelProposal())->record([
            'candidateSha256' => $candidateSha,
            'sourceSha256' => $ctx['sourceSha256'],
            'model' => 'meta-models/muse-spark-open-1026',
            'requestDigest' => str_repeat('d', 64),
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            'candidateSource' => $candidateSource,
            'find' => "max(1, \$item['quantity'])",
            'replace' => "\$item['quantity']",
            'explanation' => 'public fixture proposal',
        ]);
        [$runDir, $report, $receipt] = $this->evidenceFor($candidateSource, $candidateSha);
        $approvalStore = new ServerHeldPublicationApproval($this->dir . '/pub-approvals');
        $authToken = $approvalStore->issue($report['id'], $runDir, $receipt['sha256'],
            DedicatedTestRepo::requireBaseCommit(), $ctx['repository'], 1, 'src/OrderTotal.php',
            $report['baseSha256'], $candidateSha, $report['testSha256'], isSimulated: false);
        $repo = DedicatedTestRepo::requireName();
        $mock = new MockHttpClient(function (string $method, string $url) use ($repo): MockResponse {
            if ($url === 'https://api.github.com/user') {
                return new MockResponse(json_encode(['id' => 7, 'login' => 'owner']));
            }
            if ($url === 'https://api.github.com/repos/' . $repo) {
                return new MockResponse(json_encode(['full_name' => $repo]));
            }
            throw new \RuntimeException('No git write may follow a provenance refusal: ' . $method . ' ' . $url);
        });
        $publisher = new DedicatedTestRepoPublisher($approvalStore, $mock, disconnected: false);
        try {
            $publisher->publishDraftPr(
                ['repository' => $repo, 'issueNumber' => 1, 'path' => 'src/OrderTotal.php',
                    'proposalOrigin' => 'muse', 'model' => 'meta-models/muse-spark-open-1026', 'isPrivateDedicated' => true,
                    'privateProvenanceId' => $suggestion['privateProvenanceId'], 'requestDigest' => $suggestion['requestDigest']],
                $runDir, $candidateSource, $authToken, 'mock-token-1234567890'
            );
            self::fail('A public record must not satisfy a private provenance claim.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no private model provenance matches', $e->getMessage());
        }
    }

    public function testPublishRefusesWithoutPrivateProvenanceRecord(): void
    {
        [$suggestion, $ctx] = $this->proposeWithConsent();
        // Drop the record: revoked storage must fail closed before any git write.
        foreach (glob($this->proposalsDir . '/*.enc') ?: [] as $file) {
            @unlink($file);
        }
        $candidateSource = $suggestion['candidate']['source'];
        $candidateSha = $suggestion['candidate']['sha256'];
        [$runDir, $report, $receipt] = $this->evidenceFor($candidateSource, $candidateSha);

        $approvalStore = new ServerHeldPublicationApproval($this->dir . '/pub-approvals');
        $authToken = $approvalStore->issue($report['id'], $runDir, $receipt['sha256'],
            DedicatedTestRepo::requireBaseCommit(), $ctx['repository'], 1, 'src/OrderTotal.php',
            $report['baseSha256'], $candidateSha, $report['testSha256'], isSimulated: false);

        $repo = DedicatedTestRepo::requireName();
        $requests = [];
        $mock = new MockHttpClient(function (string $method, string $url) use (&$requests, $repo): MockResponse {
            $requests[] = $method . ' ' . $url;
            if ($url === 'https://api.github.com/user') {
                return new MockResponse(json_encode(['id' => 7, 'login' => 'owner']));
            }
            if ($url === 'https://api.github.com/repos/' . $repo) {
                return new MockResponse(json_encode(['full_name' => $repo]));
            }
            throw new \RuntimeException('No git write may follow a provenance refusal: ' . $method . ' ' . $url);
        });

        $publisher = new DedicatedTestRepoPublisher($approvalStore, $mock, disconnected: false);
        try {
            $publisher->publishDraftPr(
                ['repository' => $repo, 'issueNumber' => 1, 'path' => 'src/OrderTotal.php',
                    'proposalOrigin' => 'muse', 'model' => 'meta-models/muse-spark-open-1026', 'isPrivateDedicated' => true,
                    'privateProvenanceId' => $suggestion['privateProvenanceId'], 'requestDigest' => $suggestion['requestDigest']],
                $runDir, $candidateSource, $authToken, 'mock-token-1234567890'
            );
            self::fail('Publication without a private provenance record must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no private model provenance matches', $e->getMessage());
        }
        self::assertCount(2, $requests);
    }
}
