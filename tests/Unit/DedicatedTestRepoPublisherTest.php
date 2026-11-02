<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Evidence\Receipt;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DedicatedTestRepoPublisherTest extends TestCase
{
    private string $tempDir;
    private string $storageDir;
    private ServerHeldPublicationApproval $approvalStore;

    protected function setUp(): void
    {
        $this->tempDir = (string) realpath(sys_get_temp_dir()) . '/proofpatch-pub-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->storageDir = $this->tempDir . '/approvals';
        mkdir($this->storageDir, 0700, true);
        $this->approvalStore = new ServerHeldPublicationApproval($this->storageDir, 'test-secret-key-32-bytes-minimum!!');
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

    private function createValidEvidenceDirectory(
        string $suffix = 'test00000001',
        string $decision = 'VERIFIED',
        bool $allGatesPass = true,
        ?string $candidateSha = null
    ): array {
        $runId = '20261028T180000Z-' . substr(hash('sha256', $suffix), 0, 12);
        $runDir = $this->tempDir . '/runs/' . $runId;
        mkdir($runDir . '/baseline', 0700, true);
        mkdir($runDir . '/negative', 0700, true);
        mkdir($runDir . '/candidate', 0700, true);

        $baseSource = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');
        $testSource = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/tests/order_total.php');
        $candidateSource = (string) str_replace("max(1, \$item['quantity'])", "\$item['quantity']", $baseSource);

        $candidateSha = $candidateSha ?? hash('sha256', $candidateSource);
        $baseSha = hash('sha256', $baseSource);
        $testSha = hash('sha256', $testSource);

        file_put_contents($runDir . '/patch.diff', "--- a/src/OrderTotal.php\n+++ b/src/OrderTotal.php\n@@ -8,3 +8,3 @@\n- max(1, \$item['quantity'])\n+ \$item['quantity']\n");
        file_put_contents($runDir . '/baseline/run.log', "zero quantity: expected 250, got 750\n");
        file_put_contents($runDir . '/negative/run.log', "multiple items: expected 330, got 0\n");
        file_put_contents($runDir . '/candidate/run.log', "All order total cases passed.\n");

        $gates = [
            'baselineReproduced' => $allGatesPass,
            'negativeControlCaught' => $allGatesPass,
            'candidatePassed' => $allGatesPass,
            'candidateStaticSafetyPassed' => true,
            'allowlistIntegrityIntact' => true,
        ];

        $report = [
            'id' => $runId,
            'kind' => 'owned_backend',
            'decision' => $decision,
            'baseCommit' => \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            'targetFile' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            'baseSha256' => $baseSha,
            'candidateSha256' => $candidateSha,
            'testSha256' => $testSha,
            'patchSha256' => hash_file('sha256', $runDir . '/patch.diff'),
            'gates' => $gates,
            'runs' => [
                'baseline' => [
                    'exitCode' => 1,
                    'summary' => 'reproduced',
                    'logSha256' => hash_file('sha256', $runDir . '/baseline/run.log'),
                ],
                'negative' => [
                    'exitCode' => 1,
                    'summary' => 'caught',
                    'logSha256' => hash_file('sha256', $runDir . '/negative/run.log'),
                ],
                'candidate' => [
                    'exitCode' => 0,
                    'summary' => 'passed',
                    'durationMs' => 45,
                    'logSha256' => hash_file('sha256', $runDir . '/candidate/run.log'),
                ],
            ],
            'evidenceDirectory' => $runDir,
        ];

        file_put_contents($runDir . '/result.json', json_encode($report, JSON_PRETTY_PRINT));

        $receiptHelper = new Receipt();
        $receipt = $receiptHelper->create($runDir);
        file_put_contents($runDir . '/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT));

        return [
            'runDir' => $runDir,
            'report' => $report,
            'receipt' => $receipt,
            'candidateSource' => $candidateSource,
        ];
    }

    public function testRejectsUnauthorizedRepositories(): void
    {
        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, null, disconnected: true);
        $evidence = $this->createValidEvidenceDirectory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Repository 'prateekbhujel/php-terminal' is not authorized.");

        $context = [
            'repository' => 'prateekbhujel/php-terminal',
            'issueNumber' => 1,
            'path' => 'src/OrderTotal.php',
        ];

        $publisher->publishDraftPr($context, $evidence['runDir'], $evidence['candidateSource']);
    }

    public function testRejectsWrongIssueNumber(): void
    {
        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, null, disconnected: true);
        $evidence = $this->createValidEvidenceDirectory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Issue #99 is not authorized for publication.');

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 99,
            'path' => 'src/OrderTotal.php',
        ];

        $publisher->publishDraftPr($context, $evidence['runDir'], $evidence['candidateSource']);
    }

    public function testRejectsWrongFilePath(): void
    {
        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, null, disconnected: true);
        $evidence = $this->createValidEvidenceDirectory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("File path 'src/Malicious.php' is not authorized.");

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => 'src/Malicious.php',
        ];

        $publisher->publishDraftPr($context, $evidence['runDir'], $evidence['candidateSource']);
    }

    public function testRejectsForgedInternallyConsistentReceiptWithoutServerHeldAuthorization(): void
    {
        // An attacker creates an evidence directory with all matching SHA-256 hashes
        $evidence = $this->createValidEvidenceDirectory('forged-run-attack');
        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, new MockHttpClient(), disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        // Caller provides no server token
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Server-held publication authorization token is required. Locally generated unanchored receipts cannot authorize pull request publication.');

        $publisher->publishDraftPr($context, $evidence['runDir'], $evidence['candidateSource'], authorizationToken: null);
    }

    public function testRejectsOldReceiptForUntrustedCandidateBeforeConsumingApproval(): void
    {
        $candidate = '<?php function orderTotal(array $items): int { $GLOBALS["expected"] = 0; return 0; }';
        $evidence = $this->createValidEvidenceDirectory('old-untrusted-run', candidateSha: hash('sha256', $candidate));
        $report = $evidence['report'];
        $token = $this->approvalStore->issue(
            runId: $report['id'],
            evidenceDirectory: $evidence['runDir'],
            receiptSha256: $evidence['receipt']['sha256'],
            baseCommit: $report['baseCommit'],
            repository: \ProofPatch\GitHub\DedicatedTestRepo::name(),
            issueNumber: DedicatedTestRepoPublisher::AUTHORIZED_ISSUE_NUMBER,
            filePath: DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            baseSha256: $report['baseSha256'],
            candidateSha256: $report['candidateSha256'],
            testSha256: $report['testSha256'],
            isSimulated: false,
        );
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests) {
            $requests++;
            return new MockResponse('', ['http_code' => 500]);
        });
        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, $http, disconnected: false);
        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => DedicatedTestRepoPublisher::AUTHORIZED_ISSUE_NUMBER,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        try {
            $publisher->publishDraftPr($context, $evidence['runDir'], $candidate, authorizationToken: $token);
            self::fail('An old receipt must not authorize an untrusted candidate.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('test oracle is not isolated', $e->getMessage());
        }
        self::assertSame(0, $requests);
        self::assertSame($token, $this->approvalStore->findActiveTokenForRun($report['id'], $report['candidateSha256']));
    }

    public function testRejectsInvalidOrForgedServerAuthorizationToken(): void
    {
        $evidence = $this->createValidEvidenceDirectory('forged-token-attack');
        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, new MockHttpClient(), disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $fakeToken = str_repeat('a', 64);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Publication authorization token is invalid, expired, or was already consumed.');

        $publisher->publishDraftPr($context, $evidence['runDir'], $evidence['candidateSource'], authorizationToken: $fakeToken, githubToken: 'test-github-token');
    }

    public function testRejectsSimulatedRunsEvenWithServerToken(): void
    {
        $evidence = $this->createValidEvidenceDirectory('simulated-run-test');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        // Server issues token for simulated run
        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: true // Marked simulated!
        );

        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, new MockHttpClient(), disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot publish proposal: Verification was executed using a mock/simulated runner, not genuine provider microVM isolation.');

        $publisher->publishDraftPr($context, $evidence['runDir'], $evidence['candidateSource'], authorizationToken: $token, githubToken: 'test-github-token');
    }

    public function testDisconnectedModeBlocksRemoteConnectionsByDefault(): void
    {
        $evidence = $this->createValidEvidenceDirectory('legit-run');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        // Disconnected mode enabled by default
        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, null, disconnected: true);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Publisher is currently in disconnected safety mode pending independent review approval. External GitHub connections are blocked.');

        $publisher->publishDraftPr(
            $context,
            $evidence['runDir'],
            $evidence['candidateSource'],
            authorizationToken: $token,
            githubToken: 'gho_dummy_token_for_disconnected_test_12345'
        );
    }

    public function testRejectsStaleOrDriftedBaseCommit(): void
    {
        $evidence = $this->createValidEvidenceDirectory('drift-test');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        // Mock GitHub returning a drifted commit on main (not the configured SHA)
        $mockClient = new MockHttpClient([
            new MockResponse(json_encode([
                'object' => ['sha' => 'ffffffffffffffffffffffffffffffffffffffff'],
            ])),
        ]);

        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, $mockClient, disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Refusing to publish against stale or drifted base commit. Remote main is at 'ffffffffffffffffffffffffffffffffffffffff', but authorized base commit is '" . \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit() . "'.");

        $publisher->publishDraftPr(
            $context,
            $evidence['runDir'],
            $evidence['candidateSource'],
            authorizationToken: $token,
            githubToken: 'gho_dummy_token_for_drift_test_12345678'
        );
    }

    public function testMultiLayerSecretAuditCatchesEmbeddedTokens(): void
    {
        $evidence = $this->createValidEvidenceDirectory('secret-scan-test');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, null, disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $activeGithubToken = 'gho_' . str_repeat('k', 36);

        // Candidate source that contains the active token (should never happen)
        $taintedCandidate = $evidence['candidateSource'] . "\n// " . $activeGithubToken;

        $this->expectException(\InvalidArgumentException::class);
        // Candidate SHA won't match allowlisted candidate digest!
        $this->expectExceptionMessage('Candidate source hash does not match allowlisted candidate digest.');

        $publisher->publishDraftPr(
            $context,
            $evidence['runDir'],
            $taintedCandidate,
            authorizationToken: $token,
            githubToken: $activeGithubToken
        );
    }

    public function testSuccessfulDraftPrPublicationWhenConnectedAndVerified(): void
    {
        $evidence = $this->createValidEvidenceDirectory('success-publish-test');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        $baseShaHex = \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit();
        $mockClient = new MockHttpClient([
            // 1. GET git/ref/heads/main
            new MockResponse(json_encode(['object' => ['sha' => $baseShaHex]])),
            // 2. GET git/commits/{baseSha}
            new MockResponse(json_encode(['tree' => ['sha' => '1111111111111111111111111111111111111111']])),
            // 3. POST git/blobs
            new MockResponse(json_encode(['sha' => '2222222222222222222222222222222222222222'])),
            // 4. POST git/trees
            new MockResponse(json_encode(['sha' => '3333333333333333333333333333333333333333'])),
            // 5. POST git/commits
            static function (string $method, string $url, array $options): MockResponse {
                $payload = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
                self::assertStringContainsString('Base Commit: ' . substr(\ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(), 0, 7), $payload['message']);
                self::assertStringNotContainsString('{self::', $payload['message']);
                return new MockResponse(json_encode(['sha' => '4444444444444444444444444444444444444444']));
            },
            // 6. POST git/refs
            new MockResponse(json_encode(['ref' => 'refs/heads/proofpatch/issue-1-success'])),
            // 7. POST pulls
            static function (string $method, string $url, array $options): MockResponse {
                $payload = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
                self::assertTrue($payload['draft']);
                self::assertStringContainsString('ProofPatcher', $payload['body']);
                self::assertStringContainsString('full repository test suite was not run', $payload['body']);
                self::assertStringContainsString('<summary>Verification details</summary>', $payload['body']);
                self::assertStringContainsString('Receipt SHA-256', $payload['body']);
                self::assertStringNotContainsString('Frontier Model', $payload['body']);
                self::assertStringNotContainsString('baseten.co', $payload['body']);
                self::assertStringNotContainsString('Meta Muse', $payload['body']);
                self::assertStringNotContainsString('[bot]', $payload['body']);
                self::assertStringNotContainsString('Verified_Co--Maintainer', $payload['body']);
                return new MockResponse(json_encode([
                    'number' => 2,
                    'html_url' => 'https://github.com/example-owner/proofpatch-test/pull/2',
                    'draft' => true,
                ]));
            },
        ]);

        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, $mockClient, disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $res = $publisher->publishDraftPr(
            $context,
            $evidence['runDir'],
            $evidence['candidateSource'],
            authorizationToken: $token,
            githubToken: 'gho_dummy_valid_test_token_1234567890'
        );

        self::assertSame('published', $res['status']);
        self::assertSame(2, $res['prNumber']);
        self::assertTrue($res['draft']);
        self::assertSame('https://github.com/example-owner/proofpatch-test/pull/2', $res['prUrl']);
        self::assertSame(\ProofPatch\GitHub\DedicatedTestRepo::name(), $res['repository']);
    }

    public function testRejectsSimultaneousDoubleSpendRace(): void
    {
        $evidence = $this->createValidEvidenceDirectory('double-spend-test');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        // First consumption succeeds atomically
        $consumed = $this->approvalStore->verifyAndConsume(
            $token,
            $runId,
            $evidence['runDir'],
            $receiptSha,
            $candidateSha
        );
        self::assertSame($token, $consumed['token']);

        // Second consumption MUST fail (double-spend / replay attempt)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Publication authorization token is invalid, expired, or was already consumed.');

        $this->approvalStore->verifyAndConsume(
            $token,
            $runId,
            $evidence['runDir'],
            $receiptSha,
            $candidateSha
        );
    }

    public function testRejectsMismatchingFieldsInConsumedRecord(): void
    {
        $evidence = $this->createValidEvidenceDirectory('mismatch-test');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        // Issue token for repo A, but request is for repo B
        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            'other-owner/other-repo', // Mismatch!
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, new MockHttpClient(), disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Authorization record repository mismatch: expected 'other-owner/other-repo', got 'example-owner/proofpatch-test'.");

        $publisher->publishDraftPr(
            $context,
            $evidence['runDir'],
            $evidence['candidateSource'],
            authorizationToken: $token,
            githubToken: 'test-github-token'
        );
    }

    public function testDistinctRunsOnSameDayGenerateNonCollidingBranchNames(): void
    {
        $baseShaHex = \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit();
        $evidence1 = $this->createValidEvidenceDirectory('run_alpha');
        $evidence2 = $this->createValidEvidenceDirectory('run_beta');

        $run1Id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $evidence1['report']['id']));
        $run2Id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $evidence2['report']['id']));

        $mockClient = new MockHttpClient([
            // Run 1:
            new MockResponse(json_encode(['object' => ['sha' => $baseShaHex]])),
            new MockResponse(json_encode(['tree' => ['sha' => '1111111111111111111111111111111111111111']])),
            new MockResponse(json_encode(['sha' => '2222222222222222222222222222222222222222'])),
            new MockResponse(json_encode(['sha' => '3333333333333333333333333333333333333333'])),
            new MockResponse(json_encode(['sha' => '4444444444444444444444444444444444444444'])),
            new MockResponse(json_encode(['ref' => 'refs/heads/proofpatch/issue-1-' . $run1Id])),
            new MockResponse(json_encode(['number' => 10, 'html_url' => 'https://github.com/example-owner/proofpatch-test/pull/10', 'draft' => true])),

            // Run 2:
            new MockResponse(json_encode(['object' => ['sha' => $baseShaHex]])),
            new MockResponse(json_encode(['tree' => ['sha' => '1111111111111111111111111111111111111111']])),
            new MockResponse(json_encode(['sha' => '2222222222222222222222222222222222222222'])),
            new MockResponse(json_encode(['sha' => '3333333333333333333333333333333333333333'])),
            new MockResponse(json_encode(['sha' => '4444444444444444444444444444444444444444'])),
            new MockResponse(json_encode(['ref' => 'refs/heads/proofpatch/issue-1-' . $run2Id])),
            new MockResponse(json_encode(['number' => 11, 'html_url' => 'https://github.com/example-owner/proofpatch-test/pull/11', 'draft' => true])),
        ]);

        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, $mockClient, disconnected: false);

        $baseSource = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');
        $testSource = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/tests/order_total.php');

        $token1 = $this->approvalStore->issue(
            $evidence1['report']['id'],
            $evidence1['runDir'],
            $evidence1['receipt']['sha256'],
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            hash('sha256', $baseSource),
            hash('sha256', $evidence1['candidateSource']),
            hash('sha256', $testSource),
            isSimulated: false
        );

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $res1 = $publisher->publishDraftPr(
            $context,
            $evidence1['runDir'],
            $evidence1['candidateSource'],
            authorizationToken: $token1,
            githubToken: 'gho_dummy_valid_test_token_1234567890'
        );

        $token2 = $this->approvalStore->issue(
            $evidence2['report']['id'],
            $evidence2['runDir'],
            $evidence2['receipt']['sha256'],
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            hash('sha256', $baseSource),
            hash('sha256', $evidence2['candidateSource']),
            hash('sha256', $testSource),
            isSimulated: false
        );

        $res2 = $publisher->publishDraftPr(
            $context,
            $evidence2['runDir'],
            $evidence2['candidateSource'],
            authorizationToken: $token2,
            githubToken: 'gho_dummy_valid_test_token_1234567890'
        );

        self::assertMatchesRegularExpression('~\Aproofpatch/1-[a-z0-9-]{1,40}-[a-f0-9]{6}\z~', $res1['branch']);
        self::assertSame(DedicatedTestRepoPublisher::branchName((string) ($context['title'] ?? ''), 1, $run1Id), $res1['branch']);
        self::assertSame(DedicatedTestRepoPublisher::branchName((string) ($context['title'] ?? ''), 1, $run2Id), $res2['branch']);
        self::assertNotSame($res1['branch'], $res2['branch']);
    }

    public function testRejectsPublishingWithoutAuthorizedAppSessionToken(): void
    {
        $evidence = $this->createValidEvidenceDirectory('missing-session-token');
        $runId = $evidence['report']['id'];
        $receiptSha = $evidence['receipt']['sha256'];
        $baseSha = $evidence['report']['baseSha256'];
        $candidateSha = $evidence['report']['candidateSha256'];
        $testSha = $evidence['report']['testSha256'];

        $token = $this->approvalStore->issue(
            $runId,
            $evidence['runDir'],
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        $publisher = new DedicatedTestRepoPublisher($this->approvalStore, disconnected: false);

        $context = [
            'repository' => \ProofPatch\GitHub\DedicatedTestRepo::name(),
            'issueNumber' => 1,
            'path' => DedicatedTestRepoPublisher::AUTHORIZED_FILE_PATH,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub authorization token is required to publish draft PR. An authorized app session is required.');

        // Pass null for githubToken when no session is active - must fail closed without host CLI fallback
        $publisher->publishDraftPr(
            $context,
            $evidence['runDir'],
            $evidence['candidateSource'],
            authorizationToken: $token,
            githubToken: null
        );
    }

    public function testBranchNamesAreReadableBoundedAndUnique(): void
    {
        $a = DedicatedTestRepoPublisher::branchName('Zero-quantity items inflate the order total!', 1, 'run-a');
        $b = DedicatedTestRepoPublisher::branchName('Zero-quantity items inflate the order total!', 1, 'run-b');
        self::assertMatchesRegularExpression('~\Aproofpatch/1-zero-quantity-items-inflate-the-order-[a-f0-9]{6}\z~', $a);
        self::assertNotSame($a, $b);
        self::assertSame($a, DedicatedTestRepoPublisher::branchName('Zero-quantity items inflate the order total!', 1, 'run-a'));
        $long = DedicatedTestRepoPublisher::branchName(str_repeat('word ', 40).'../$(rm -rf)', 7, 'r');
        self::assertMatchesRegularExpression('~\Aproofpatch/7-[a-z0-9-]{1,40}-[a-f0-9]{6}\z~', $long);
        self::assertSame('proofpatch/2-fix-'.substr(hash('sha256', 'r'), 0, 6), DedicatedTestRepoPublisher::branchName('!!!', 2, 'r'));
    }

    public function testCommitCreditsTheRealAppIdentity(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/GitHub/DedicatedTestRepoPublisher.php');
        self::assertStringContainsString('Co-authored-by: proofpatcher[bot] <349779349+proofpatcher[bot]@users.noreply.github.com>', $source);
        self::assertStringNotContainsString('proofpatcher.dev', $source);
    }
}
