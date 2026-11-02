<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PullRequestReviewIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/proofpatch_pr_integration_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $ledger = $this->tempDir.'/runtime-ledger.json';
        if (file_exists($ledger)) {
            @unlink($ledger);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    private function samplePull(): array
    {
        return [
            'repository' => 'laravel/framework',
            'number' => 50123,
            'title' => 'Prevent null pointer exception when cache key is empty',
            'headSha' => 'a1b2c3d4e5f678901234567890abcdef12345678',
            'baseSha' => 'f6e5d4c3b2a109876543210fedcba09876543210',
        ];
    }

    private function samplePatchPreview(): array
    {
        return [
            'repository' => 'laravel/framework',
            'number' => 50123,
            'headSha' => 'a1b2c3d4e5f678901234567890abcdef12345678',
            'baseSha' => 'f6e5d4c3b2a109876543210fedcba09876543210',
            'changedFiles' => 2,
            'filesTruncated' => false,
            'files' => [
                [
                    'name' => 'src/Illuminate/Cache/Repository.php',
                    'status' => 'available',
                    'patch' => "@@ -40,2 +40,5 @@\n \$existing = true;\n return false;\n+        if (\$key === '') {\n+            return null;\n+        }",
                ],
                [
                    'name' => 'tests/Cache/RepositoryTest.php',
                    'status' => 'available',
                    'patch' => "@@ -100,2 +100,6 @@\n \$this->setUpCache();\n \$cache = \$this->cache;\n+    public function testEmptyKeyReturnsNull(): void\n+    {\n+        \$this->assertNull(\$cache->get(''));\n+    }",
                ],
            ],
        ];
    }

    public function testFullReviewFlowWithMockedProviderAndBudgetTracking(): void
    {
        $budget = new RequestBudget($this->tempDir, 150000, 4);

        $mockModelJson = json_encode([
            'summary' => 'Adds early return for empty cache key and a corresponding regression test.',
            'riskLevel' => 'low',
            'recommendation' => 'approve',
            'fileFindings' => [
                [
                    'path' => 'src/Illuminate/Cache/Repository.php',
                    'status' => 'approval',
                    'lineHint' => 42,
                    'comment' => 'Clean defensive check for empty cache key.',
                ],
                [
                    'path' => 'tests/Cache/RepositoryTest.php',
                    'status' => 'approval',
                    'lineHint' => 102,
                    'comment' => 'Valid unit test covering the edge case.',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse(json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [
                [
                    'finish_reason' => 'stop',
                    'message' => ['content' => $mockModelJson],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 380,
                'completion_tokens' => 140,
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);

        $http = new MockHttpClient([$mockResponse]);
        $agent = new PullRequestReview($http, $budget);

        // 1. Preview
        $preview = $agent->preview($this->samplePull(), $this->samplePatchPreview());
        self::assertSame(2, $preview['filesCount']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $preview['digest']);
        self::assertGreaterThan(0, $preview['estimatedMicros']);

        // Check budget initial state
        $initialStatus = $budget->status();
        self::assertSame(0, $initialStatus['attempts']);
        self::assertSame(150000, $initialStatus['remainingMicros']);

        // 2. Review with approved digest
        $result = $agent->review(
            $this->samplePull(),
            $this->samplePatchPreview(),
            $preview['digest'],
            'test-baseten-secret-key-123456789'
        );

        self::assertTrue($result['unverified']);
        self::assertSame('low', $result['riskLevel']);
        self::assertSame('approve', $result['recommendation']);
        self::assertCount(2, $result['fileFindings']);
        self::assertSame($preview['digest'], $result['inputDigest']);
        self::assertSame(380, $result['usage']['prompt_tokens']);
        self::assertSame(140, $result['usage']['completion_tokens']);

        // Assert strictly validated anchor commentability:
        // Line 41 is an added line in Repository.php -> commentable = true
        self::assertTrue($result['fileFindings'][0]['commentable']);
        self::assertSame('anchored', $result['fileFindings'][0]['anchorStatus']);
        self::assertSame('addition', $result['fileFindings'][0]['lineType']);

        // Line 101 is an added line in RepositoryTest.php -> commentable = true
        self::assertTrue($result['fileFindings'][1]['commentable']);
        self::assertSame('anchored', $result['fileFindings'][1]['anchorStatus']);

        // Check budget updated state
        $afterStatus = $budget->status();
        self::assertSame(1, $afterStatus['attempts']);
        self::assertLessThan(150000, $afterStatus['remainingMicros']);
    }

    public function testUnanchoredFindingIsExplicitlyMarkedNotCommentable(): void
    {
        $budget = new RequestBudget($this->tempDir, 150000, 4);

        $mockModelJson = json_encode([
            'summary' => 'Finding outside captured diff hunk.',
            'riskLevel' => 'medium',
            'recommendation' => 'discuss',
            'fileFindings' => [
                [
                    'path' => 'src/Illuminate/Cache/Repository.php',
                    'status' => 'concern',
                    // Positive lineHint 999 is outside the hunk (lines 40..44)
                    'lineHint' => 999,
                    'comment' => 'Potential memory leak at end of file.',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse(json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [
                [
                    'finish_reason' => 'stop',
                    'message' => ['content' => $mockModelJson],
                ],
            ],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 60],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);

        $http = new MockHttpClient([$mockResponse]);
        $agent = new PullRequestReview($http, $budget);

        $preview = $agent->preview($this->samplePull(), $this->samplePatchPreview());
        $result = $agent->review(
            $this->samplePull(),
            $this->samplePatchPreview(),
            $preview['digest'],
            'test-baseten-secret-key-123456789'
        );

        $finding = $result['fileFindings'][0];
        // Must NOT be commentable despite positive lineHint 999!
        self::assertFalse($finding['commentable']);
        self::assertSame('hint_only', $finding['anchorStatus']);
        self::assertStringContainsString('outside the captured diff hunks', $finding['anchorMessage']);
    }

    public function testReplayPreventionWithServerHeldSingleUseApproval(): void
    {
        $budget = new RequestBudget($this->tempDir, 150000, 4);
        $approvalsStore = new \ProofPatch\Agent\SingleUseReviewApproval($this->tempDir.'/approvals', 1800);

        $mockModelJson = json_encode([
            'summary' => 'Single-use approval test.',
            'riskLevel' => 'low',
            'recommendation' => 'approve',
            'fileFindings' => [],
        ], JSON_THROW_ON_ERROR);

        $mockResponse1 = new MockResponse(json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $mockModelJson]]],
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 40],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);

        $http = new MockHttpClient([$mockResponse1]);
        $agent = new PullRequestReview($http, $budget);

        $pull = $this->samplePull();
        $preview = $agent->preview($pull, $this->samplePatchPreview());

        // 1. Issue server-held approval token
        $approval = $approvalsStore->issue($pull['repository'], $pull['number'], $pull['headSha'], $preview['digest']);
        $token = $approval['token'];

        // 2. Request 1: consumes token and performs review
        $approvalsStore->consume($token, $pull['repository'], $pull['number'], $pull['headSha'], $preview['digest']);
        $result1 = $agent->review($pull, $this->samplePatchPreview(), $preview['digest'], 'test-key-1234567890123');
        self::assertTrue($result1['unverified']);

        $statusAfterRequest1 = $budget->status();
        self::assertSame(1, $statusAfterRequest1['attempts']);

        // 3. Request 2 (Replay / Duplicate POST): attempts to consume the same token again
        $replayBlocked = false;
        try {
            $approvalsStore->consume($token, $pull['repository'], $pull['number'], $pull['headSha'], $preview['digest']);
            // If it didn't throw, attempt second review (which would spend budget)
            $agent->review($pull, $this->samplePatchPreview(), $preview['digest'], 'test-key-1234567890123');
        } catch (\RuntimeException $e) {
            $replayBlocked = true;
            self::assertStringContainsString('already been consumed', $e->getMessage());
        }

        self::assertTrue($replayBlocked, 'Replayed review request must be blocked fail-closed before provider call.');

        // 4. Verify budget was NOT charged a second time
        $statusAfterReplay = $budget->status();
        self::assertSame(1, $statusAfterReplay['attempts'], 'Budget attempts must remain 1 after replay attempt.');
        self::assertSame($statusAfterRequest1['remainingMicros'], $statusAfterReplay['remainingMicros'], 'Remaining budget must not decrease on replayed request.');
    }

    public function testBudgetExhaustionFailsClosed(): void
    {
        // Limit of only 1 attempt
        $budget = new RequestBudget($this->tempDir, 150000, 1);

        $mockModelJson = json_encode([
            'summary' => 'Initial review.',
            'riskLevel' => 'low',
            'recommendation' => 'approve',
            'fileFindings' => [],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse(json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $mockModelJson]]],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 50],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);

        $http = new MockHttpClient([$mockResponse]);
        $agent = new PullRequestReview($http, $budget);

        $preview = $agent->preview($this->samplePull(), $this->samplePatchPreview());
        $agent->review($this->samplePull(), $this->samplePatchPreview(), $preview['digest'], 'test-key-1234567890123');

        // Second attempt must fail closed because maxAttempts = 1
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The configured request cap is reached.');

        $agent->review($this->samplePull(), $this->samplePatchPreview(), $preview['digest'], 'test-key-1234567890123');
    }

    public function testDigestTamperingRejectionPreventsNetworkCall(): void
    {
        $budget = new RequestBudget($this->tempDir, 150000, 4);
        // HttpClient with 0 responses: if network is attempted, it throws an exception from MockHttpClient
        $http = new MockHttpClient([]);
        $agent = new PullRequestReview($http, $budget);

        $preview = $agent->preview($this->samplePull(), $this->samplePatchPreview());

        // Tamper with digest
        $tampered = substr_replace($preview['digest'], '0', 0, 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Review and approve the exact request before sending it.');

        $agent->review($this->samplePull(), $this->samplePatchPreview(), $tampered, 'test-key-1234567890123');
    }

    public function testPreviewContainsCompleteMessageBodyForInspection(): void
    {
        $budget = new RequestBudget($this->tempDir, 150000, 4);
        $agent = new PullRequestReview(new MockHttpClient([]), $budget);

        $preview = $agent->preview($this->samplePull(), $this->samplePatchPreview());

        self::assertArrayHasKey('body', $preview);
        self::assertSame(PullRequestReview::MODEL, $preview['body']['model']);
        self::assertArrayHasKey('messages', $preview['body']);
        self::assertCount(2, $preview['body']['messages']);

        $userMsgJson = $preview['body']['messages'][1]['content'];
        $decoded = json_decode($userMsgJson, true);
        self::assertSame('laravel/framework', $decoded['repository']);
        self::assertSame(50123, $decoded['number']);
        self::assertSame('a1b2c3d4e5f678901234567890abcdef12345678', $decoded['headSha']);
        self::assertCount(2, $decoded['files']);
    }

    public function testAnchorValidationDistinguishesAddedLinesFromHintsAndStaleHeads(): void
    {
        $patchPreview = $this->samplePatchPreview();
        $repoFilePatch = $patchPreview['files'][0]['patch'];
        $head = 'a1b2c3d4e5f678901234567890abcdef12345678';

        // 1. Anchored to added line 42
        $anchor = \ProofPatch\GitHub\DiffHunkParser::validateAnchor($repoFilePatch, 42, $head, $head);
        self::assertTrue($anchor['valid']);
        self::assertSame('anchored', $anchor['status']);
        self::assertSame('addition', $anchor['lineType']);

        // 2. Line outside hunk is hint only
        $hint = \ProofPatch\GitHub\DiffHunkParser::validateAnchor($repoFilePatch, 500, $head, $head);
        self::assertFalse($hint['valid']);
        self::assertSame('hint_only', $hint['status']);

        // 3. Stale head commit is rejected
        $stale = \ProofPatch\GitHub\DiffHunkParser::validateAnchor($repoFilePatch, 42, 'ffffffffffffffffffffffffffffffffffffffff', $head);
        self::assertFalse($stale['valid']);
        self::assertSame('stale', $stale['status']);
    }
}
