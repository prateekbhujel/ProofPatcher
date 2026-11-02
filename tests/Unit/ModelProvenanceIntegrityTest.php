<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\ServerHeldModelProposal;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use ProofPatch\Http\Controllers\IssueWorkbenchController;
use Illuminate\Http\Request;
use Symfony\Component\HttpClient\MockHttpClient;

final class ModelProvenanceIntegrityTest extends TestCase
{
    use \ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

    private string $tempDir;
    private string $proposalsDir;
    private string $approvalsDir;
    private string $evidenceDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/proofpatch_prov_test_' . bin2hex(random_bytes(6));
        $this->proposalsDir = $this->tempDir . '/proposals';
        $this->approvalsDir = $this->tempDir . '/approvals';
        $this->evidenceDir = $this->tempDir . '/evidence';

        mkdir($this->tempDir, 0700, true);
        mkdir($this->proposalsDir, 0700, true);
        mkdir($this->approvalsDir, 0700, true);
        mkdir($this->evidenceDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tempDir);
        }
    }

    public function testServerHeldModelProposalCryptographicIntegrity(): void
    {
        $store = new ServerHeldModelProposal($this->proposalsDir, 'test-server-secret-key-1234567890');
        $candidateSource = "<?php\nfunction orderTotal(array \$items): int { return 100; }\n";
        $candidateSha = hash('sha256', $candidateSource);
        $sourceSha = hash('sha256', "original source");
        $requestDigest = hash('sha256', "request payload");

        $store->record([
            'candidateSha256' => $candidateSha,
            'sourceSha256' => $sourceSha,
            'model' => 'meta-models/muse-spark-open-1026',
            'requestDigest' => $requestDigest,
            'usage' => ['prompt_tokens' => 503, 'completion_tokens' => 365],
            'candidateSource' => $candidateSource,
            'find' => 'foo',
            'replace' => 'bar',
            'explanation' => 'Fix calculation',
        ]);

        $record = $store->lookup($candidateSha);
        self::assertNotNull($record);
        self::assertSame($candidateSha, $record['candidateSha256']);
        self::assertSame('meta-models/muse-spark-open-1026', $record['model']);
        self::assertSame(503, $record['usage']['prompt_tokens']);
        self::assertSame(365, $record['usage']['completion_tokens']);

        // Tampering with the file invalidates the HMAC signature
        $filePath = $this->proposalsDir . '/' . $candidateSha . '.json';
        $json = json_decode((string) file_get_contents($filePath), true);
        $json['usage']['prompt_tokens'] = 999999; // tampered
        file_put_contents($filePath, json_encode($json));

        self::assertNull($store->lookup($candidateSha), 'Tampered proposal file must fail HMAC verification.');
    }

    public function testDirectMetaStandardModelCanRecordProvenance(): void
    {
        $store = new ServerHeldModelProposal($this->proposalsDir, 'test-server-secret-key-1234567890');
        $source = '<?php return 1;';
        $sha = hash('sha256', $source);
        $store->record(['candidateSha256' => $sha, 'sourceSha256' => hash('sha256', 'base'),
            'model' => 'muse-spark-1.3', 'requestDigest' => hash('sha256', 'request'),
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5], 'candidateSource' => $source]);
        self::assertSame('muse-spark-1.3', $store->lookup($sha)['model']);
        $this->expectException(\InvalidArgumentException::class);
        $store->record(['candidateSha256' => $sha, 'sourceSha256' => hash('sha256', 'base'),
            'model' => 'untrusted-model', 'requestDigest' => hash('sha256', 'request'),
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5], 'candidateSource' => $source]);
    }

    public function testPublisherRejectsMuseProvenanceWithoutMatchingServerHeldProposal(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $approvalStore = new ServerHeldPublicationApproval($this->approvalsDir);
        $verifier = new \ProofPatch\Verifier\OwnedCaseVerifier($projectRoot, $this->evidenceDir, $approvalStore);

        $baseFixture = (string) file_get_contents($projectRoot . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');
        $candidateSource = str_replace("max(1, \$item['quantity'])", "\$item['quantity']", $baseFixture);

        $report = $verifier->verify($candidateSource, [
            'repository' => DedicatedTestRepo::requireName(),
            'issueNumber' => 1,
            'commit' => DedicatedTestRepo::requireBaseCommit(),
            'path' => 'src/OrderTotal.php',
            'sourceSha256' => DedicatedTestRepoPublisher::KNOWN_BASE_SHA256,
        ]);

        $token = $report['publicationAuthorizationToken'];
        $evidenceDirectory = $report['evidenceDirectory'];

        $publisher = new DedicatedTestRepoPublisher($approvalStore, disconnected: true,
            modelProposals: new ServerHeldModelProposal($this->proposalsDir, 'test-server-secret-key-1234567890'));

        // Attempt publication claiming 'muse' origin without any server-held proposal
        $context = [
            'repository' => DedicatedTestRepo::requireName(),
            'issueNumber' => 1,
            'path' => 'src/OrderTotal.php',
            'proposalOrigin' => 'muse',
            'model' => 'meta-models/muse-spark-open-1026',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Publication refused: Candidate claims Muse model provenance but has no verified server-held model proposal.');

        $publisher->publishDraftPr($context, $evidenceDirectory, $candidateSource, $token, 'dummy-gh-token');
    }

    public function testPublisherRejectsChangedOrMismatchedCandidateBytes(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $approvalStore = new ServerHeldPublicationApproval($this->approvalsDir);
        $verifier = new \ProofPatch\Verifier\OwnedCaseVerifier($projectRoot, $this->evidenceDir, $approvalStore);

        $baseFixture = (string) file_get_contents($projectRoot . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');
        $validCandidate = str_replace("max(1, \$item['quantity'])", "\$item['quantity']", $baseFixture);

        $report = $verifier->verify($validCandidate, [
            'repository' => DedicatedTestRepo::requireName(),
            'issueNumber' => 1,
            'commit' => DedicatedTestRepo::requireBaseCommit(),
            'path' => 'src/OrderTotal.php',
            'sourceSha256' => DedicatedTestRepoPublisher::KNOWN_BASE_SHA256,
        ]);

        $token = $report['publicationAuthorizationToken'];
        $evidenceDirectory = $report['evidenceDirectory'];

        $publisher = new DedicatedTestRepoPublisher($approvalStore, disconnected: true);
        $context = [
            'repository' => DedicatedTestRepo::requireName(),
            'issueNumber' => 1,
            'path' => 'src/OrderTotal.php',
            'proposalOrigin' => 'offline_reference',
        ];

        $tamperedCandidate = $validCandidate . "\n// tampered";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Candidate source hash does not match allowlisted candidate digest.');

        $publisher->publishDraftPr($context, $evidenceDirectory, $tamperedCandidate, $token, 'dummy-gh-token');
    }

    public function testRequestParametersCannotFabricateMuseProvenanceOnDedicatedTask(): void
    {
        $this->setupIsolatedDatabase();
        $app = $this->bootstrapAndMigrateIsolatedDatabase();

        $session = $app->make('session')->driver('array');
        $session->start();
        $baseFixture = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');

        $session->put('issue_is_dedicated_test_repo', true);
        $session->put('issue_context', [
            'repository' => DedicatedTestRepo::requireName(),
            'issueNumber' => 1,
            'issueUrl' => 'https://github.com/' . DedicatedTestRepo::requireName() . '/issues/1',
            'title' => 'Fix zero-quantity order calculation',
            'body' => 'In OrderTotal::calculate, zero-quantity items should contribute zero.',
            'commit' => DedicatedTestRepo::requireBaseCommit(),
            'path' => 'src/OrderTotal.php',
            'source' => $baseFixture,
            'sourceSha256' => hash('sha256', $baseFixture),
        ]);

        $context = $session->get('issue_context');
        $budget = RequestBudget::forRuntime();
        $http = new MockHttpClient();
        $preview = (new IssueSuggestion($http, $budget))->preview($context);

        $controller = new IssueWorkbenchController($http);

        // Adversarial request trying to claim muse origin and fake tokens
        $proposeReq = Request::create('/issues/propose', 'POST', [
            'approved' => true,
            'digest' => $preview['digest'],
            'candidate_origin' => 'muse',
            'use_muse_candidate' => true,
            'model' => 'meta-models/muse-spark-open-1026',
            'tokens' => ['prompt_tokens' => 999, 'completion_tokens' => 999],
            'offline_reference' => true,
        ]);
        $session->put('github_auth', ['token' => 'gho_dummy_token_for_test_1234567890']);
        $proposeReq->setLaravelSession($session);
        $app->instance('request', $proposeReq);
        $controller->propose($proposeReq);

        $suggestion = $session->get('issue_suggestion');
        self::assertIsArray($suggestion);
        self::assertTrue($suggestion['isOfflineExample'], 'Must strictly be labeled as offline reference example');
        self::assertNull($suggestion['model'], 'Model name must NOT be set from request parameters');
        self::assertNull($suggestion['usage'], 'Usage tokens must NOT be set from request parameters');
        self::assertSame('offline_reference_example', $suggestion['status']);

        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testDedicatedTaskCarriesVerifiedServerHeldMuseProposalWhenSourceMatches(): void
    {
        $this->setupIsolatedDatabase();
        $app = $this->bootstrapAndMigrateIsolatedDatabase();

        $session = $app->make('session')->driver('array');
        $session->start();
        $baseFixture = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');
        $baseSha = hash('sha256', $baseFixture);

        $genuineCandidate = str_replace(
            "        \$total += \$item['unit_price'] * max(1, \$item['quantity']);",
            "        \$total += \$item['unit_price'] * max(0, \$item['quantity']);",
            $baseFixture
        );
        $candidateSha = hash('sha256', $genuineCandidate);

        $proposalStore = new ServerHeldModelProposal($this->proposalsDir, 'test-secret-key-1234567890123456');
        $proposalStore->record([
            'candidateSha256' => $candidateSha,
            'sourceSha256' => $baseSha,
            'model' => 'meta-models/muse-spark-open-1026',
            'requestDigest' => hash('sha256', 'mock_digest_for_test'),
            'usage' => [
                'prompt_tokens' => 503,
                'completion_tokens' => 435,
            ],
            'find' => "        \$total += \$item['unit_price'] * max(1, \$item['quantity']);",
            'replace' => "        \$total += \$item['unit_price'] * max(0, \$item['quantity']);",
            'explanation' => 'Change max lower bound from 1 to 0.',
            'candidateSource' => $genuineCandidate,
            'githubUserId' => 13213140,
            'repository' => DedicatedTestRepo::requireName(),
            'issueNumber' => 1,
            'path' => 'src/OrderTotal.php',
        ]);

        // Explicit lookup with matching context succeeds
        $found = $proposalStore->lookup($candidateSha, [
            'sourceSha256' => $baseSha,
            'repository' => DedicatedTestRepo::requireName(),
        ]);
        self::assertNotNull($found);
        self::assertSame($candidateSha, $found['candidateSha256']);
        self::assertSame('meta-models/muse-spark-open-1026', $found['model']);

        // Mismatched context rejects lookup
        self::assertNull($proposalStore->lookup($candidateSha, ['sourceSha256' => hash('sha256', 'different')]));

        // Tampering with displayed explanation invalidates HMAC
        $filePath = $this->proposalsDir . '/' . $candidateSha . '.json';
        $json = json_decode((string) file_get_contents($filePath), true);
        $json['explanation'] = 'Tampered explanation text';
        file_put_contents($filePath, json_encode($json));
        self::assertNull($proposalStore->lookup($candidateSha));

        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }
}
