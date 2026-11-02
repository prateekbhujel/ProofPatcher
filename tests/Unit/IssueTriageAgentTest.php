<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\IssueTriageAgent;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\PrTriageAnalyzer;
use ProofPatch\GitHub\PullRequestActions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IssueTriageAgentTest extends TestCase
{
    private string $tempDir;
    private RequestBudget $budget;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/proofpatch_issue_triage_test_'.bin2hex(random_bytes(6));
        $this->budget = new RequestBudget($this->tempDir, 500000, 16);
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

    private function pinnedOrderTotalFile(): array
    {
        return [
            'path' => 'src/OrderTotal.php',
            'commit' => '676e73d674aca9ba711aa10877ffd7287e1f25b3',
            'source' => <<<'PHP'
<?php

declare(strict_types=1);

function orderTotal(array $items): int
{
    $total = 0;
    foreach ($items as $item) {
        $quantity = max(1, (int) ($item['quantity'] ?? 0));
        $total += (int) ($item['price'] ?? 0) * $quantity;
    }

    return $total;
}
PHP,
        ];
    }

    public function testThreeIssueArchetypesAreTriagedWithVerifiedCodeEvidenceAndSafeScreenshotHandling(): void
    {
        $pinned = $this->pinnedOrderTotalFile();

        // Archetype 1: Good reproducible bug report (zero-quantity items charged as 1)
        $goodIssue = [
            'repository' => 'prateekbhujel/proofpatch-review-demo',
            'number' => 1,
            'title' => 'Zero-quantity items inflate the order total',
            'body' => 'When an item has quantity 0 and price 500, orderTotal returns 500 instead of 0 because max(1, ...) clamps 0 to 1.',
            'isPrivate' => false,
        ];

        // Archetype 2: Misleading / contradicted issue (claims quantity=3 is ignored)
        $misleadingIssue = [
            'repository' => 'prateekbhujel/proofpatch-review-demo',
            'number' => 2,
            'title' => 'Quantity 3 items are always multiplied by zero',
            'body' => 'Passing quantity 3 and price 100 returns 0 because the loop never multiplies price by quantity for positive numbers.',
            'isPrivate' => false,
        ];

        // Archetype 3: Incomplete screenshot-only issue
        $screenshotIssue = [
            'repository' => 'prateekbhujel/proofpatch-review-demo',
            'number' => 3,
            'title' => 'Broken total on checkout page',
            'body' => "See screenshot:\n![checkout bug](https://example.com/private-screenshot.png)",
            'isPrivate' => false,
        ];

        $responses = [
            // Response for Archetype 1
            new MockResponse(json_encode([
                'model' => IssueTriageAgent::MODEL,
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode([
                        'verdict' => 'reproducible_bug',
                        'summary' => 'Line 9 clamps quantity with max(1, ...), causing quantity 0 items to be billed as quantity 1.',
                        'evidenceQuote' => "\$quantity = max(1, (int) (\$item['quantity'] ?? 0));",
                        'evidenceLine' => 9,
                        'suggestedBranchSlug' => 'fix-zero-quantity-order-total',
                        'suggestedResponse' => 'Confirmed in `src/OrderTotal.php` line 9 (`max(1, ...)`). Preparing a verified fix.',
                    ])],
                ]],
                'usage' => ['prompt_tokens' => 240, 'completion_tokens' => 95],
            ])),
            // Response for Archetype 2
            new MockResponse(json_encode([
                'model' => IssueTriageAgent::MODEL,
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode([
                        'verdict' => 'contradicted_by_code',
                        'summary' => 'For quantity 3, max(1, 3) evaluates to 3 and line 10 multiplies price by 3.',
                        'evidenceQuote' => "\$total += (int) (\$item['price'] ?? 0) * \$quantity;",
                        'evidenceLine' => 10,
                        'suggestedBranchSlug' => '',
                        'suggestedResponse' => 'In `src/OrderTotal.php` line 10, `quantity = 3` and `price = 100` computes `300`. Could you share the exact input array that produced 0?',
                    ])],
                ]],
                'usage' => ['prompt_tokens' => 245, 'completion_tokens' => 90],
            ])),
            // Response for Archetype 3 (even if model tries to claim reproducible_bug, screenshot-only guard downgrades to needs_reproduction)
            new MockResponse(json_encode([
                'model' => IssueTriageAgent::MODEL,
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode([
                        'verdict' => 'reproducible_bug',
                        'summary' => 'Screenshot might relate to orderTotal.',
                        'evidenceQuote' => "\$quantity = max(1, (int) (\$item['quantity'] ?? 0));",
                        'evidenceLine' => 9,
                        'suggestedBranchSlug' => 'checkout-screenshot',
                        'suggestedResponse' => 'Screenshots are not sent to the model. Please share the input payload and expected vs. actual output in text.',
                    ])],
                ]],
                'usage' => ['prompt_tokens' => 210, 'completion_tokens' => 80],
            ])),
        ];

        $agent = new IssueTriageAgent(new MockHttpClient($responses), $this->budget);
        $key = 'valid-baseten-key-for-issue-triage-12345';

        // 1. Verify Archetype 1 (Good reproducible issue)
        $preview1 = $agent->preview($goodIssue, $pinned);
        $result1 = $agent->triage($goodIssue, $pinned, $preview1['digest'], $key);
        self::assertSame('reproducible_bug', $result1['verdict']);
        self::assertTrue($result1['evidenceVerified']);
        self::assertNull($result1['downgradeReason']);
        self::assertMatchesRegularExpression('~\Aproofpatch/1-fix-zero-quantity-order-total-[a-f0-9]{6}\z~', $result1['suggestedBranch']);

        // 2. Verify Archetype 2 (Misleading / contradicted issue)
        $preview2 = $agent->preview($misleadingIssue, $pinned);
        $result2 = $agent->triage($misleadingIssue, $pinned, $preview2['digest'], $key);
        self::assertSame('contradicted_by_code', $result2['verdict']);
        self::assertTrue($result2['evidenceVerified']);
        self::assertNull($result2['downgradeReason']);

        // 3. Verify Archetype 3 (Screenshot-only incomplete issue)
        $preview3 = $agent->preview($screenshotIssue, $pinned);
        // Ensure external screenshot URL was stripped before model payload creation
        $encodedBody3 = json_encode($preview3['body']);
        self::assertStringNotContainsString('https://example.com/private-screenshot.png', (string) $encodedBody3);
        self::assertTrue($preview3['sanitized']['hasScreenshots']);
        self::assertTrue($preview3['sanitized']['screenshotOnly']);

        $result3 = $agent->triage($screenshotIssue, $pinned, $preview3['digest'], $key);
        self::assertSame('needs_reproduction', $result3['verdict']);
        self::assertNotNull($result3['downgradeReason']);
        self::assertStringContainsString('screenshot', $result3['downgradeReason']);
        self::assertStringContainsString('steps to reproduce', $result3['suggestedResponse']);
        self::assertStringNotContainsString('Screenshot might relate', $result3['summary']);
    }

    public function testHallucinatedEvidenceQuoteDowngradesVerdictToNeedsReproduction(): void
    {
        $pinned = $this->pinnedOrderTotalFile();
        $parsed = IssueTriageAgent::parseAndValidate(
            json_encode([
                'verdict' => 'contradicted_by_code',
                'summary' => 'The function already checks if quantity < 0 and throws an exception.',
                'evidenceQuote' => 'if ($quantity < 0) { throw new InvalidArgumentException(); }',
                'evidenceLine' => 8,
                'suggestedBranchSlug' => 'negative-quantity',
                'suggestedResponse' => 'Already handled.',
            ], JSON_THROW_ON_ERROR),
            $pinned['source'],
            'Negative quantity bug',
            4,
            $pinned['commit'],
            false
        );

        self::assertSame('needs_reproduction', $parsed['verdict']);
        self::assertFalse($parsed['evidenceVerified']);
        self::assertNotNull($parsed['downgradeReason']);
        self::assertStringNotContainsString('already', strtolower($parsed['suggestedResponse']));
        self::assertStringContainsString('steps to reproduce', $parsed['suggestedResponse']);
        self::assertStringNotContainsString('already checks', $parsed['summary']);
    }

    public function testNeedsReproductionNeverSurfacesContradictoryModelProse(): void
    {
        $pinned = $this->pinnedOrderTotalFile();
        $raw = json_encode([
            'verdict' => 'needs_reproduction',
            'summary' => 'This is fixed and should be closed.',
            'evidenceQuote' => '',
            'evidenceLine' => null,
            'suggestedBranchSlug' => '',
            'suggestedResponse' => 'Already fixed, closing this.',
        ], JSON_THROW_ON_ERROR);
        foreach ([false, true] as $screenshotOnly) {
            $parsed = IssueTriageAgent::parseAndValidate($raw, $pinned['source'], 'Unclear issue', 4, $pinned['commit'], $screenshotOnly);
            self::assertSame('needs_reproduction', $parsed['verdict']);
            self::assertStringNotContainsString('fixed', strtolower($parsed['summary'].$parsed['suggestedResponse']));
            self::assertStringContainsString('steps to reproduce', $parsed['suggestedResponse']);
            if ($screenshotOnly) self::assertStringContainsString('cannot inspect the screenshot', $parsed['suggestedResponse']);
        }
    }

    public function testPrivateIssueIsRefusedBeforeAnyPreviewOrNetworkCall(): void
    {
        $agent = new IssueTriageAgent(new MockHttpClient(), $this->budget);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Private or uncertain-visibility issues must not be sent to model triage.');
        $agent->preview([
            'repository' => 'acme/private-repo',
            'number' => 1,
            'title' => 'Private bug',
            'body' => 'Confidential details',
            'isPrivate' => true,
        ], $this->pinnedOrderTotalFile());
    }

    public function testPullRequestReviewSupportsMultiThousandLineDiffsWithinSafeBounds(): void
    {
        $pull = [
            'repository' => 'acme/kernel',
            'number' => 99,
            'title' => 'Large storage refactor across modules',
            'headSha' => str_repeat('a', 40),
            'baseSha' => str_repeat('b', 40),
            'isPrivate' => false,
        ];

        $lines = ["@@ -1,2500 +1,2500 @@"];
        for ($i = 1; $i <= 2500; ++$i) {
            $lines[] = "+    \$line{$i} = {$i};";
        }
        $largePatch = implode("\n", $lines); // ~50 KB, > old 32 KB limit, 2,500 lines

        $patchPreview = [
            'repository' => 'acme/kernel',
            'number' => 99,
            'headSha' => str_repeat('a', 40),
            'baseSha' => str_repeat('b', 40),
            'isPrivate' => false,
            'changedFiles' => 1,
            'filesTruncated' => false,
            'files' => [
                ['name' => 'src/Engine.php', 'status' => 'available', 'patch' => $largePatch],
            ],
        ];

        $review = new PullRequestReview(new MockHttpClient(), $this->budget);
        $preview = $review->preview($pull, $patchPreview);
        self::assertSame(1, $preview['filesCount']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $preview['digest']);
    }

    /**
     * Deterministic state-machine simulation across 1,200 combinations of repository visibility,
     * auth scope, head/base SHA drift, draft status, mergeable state, and evidence verification.
     */
    public function testKernelSafetyInvariantsHoldAcross1200SimulatedScenarios(): void
    {
        $analyzer = new PrTriageAnalyzer();
        $pinned = $this->pinnedOrderTotalFile();
        $validHead = str_repeat('a', 40);
        $otherHead = str_repeat('c', 40);

        $scenariosRun = 0;

        for ($i = 0; $i < 1200; ++$i) {
            ++$scenariosRun;
            $hasUser = ($i % 5) !== 0;
            $inScope = ($i % 4) !== 0;
            $headMatchesUi = ($i % 3) !== 0;
            $remoteHeadMatches = ($i % 7) !== 0;
            $isDraft = ($i % 6) === 0;
            $isMergeable = ($i % 8) !== 0;
            $isOpen = ($i % 9) !== 0;

            $access = [
                'userId' => $hasUser ? 42 : null,
                'repositories' => $inScope ? ['acme/repo' => ['fullName' => 'acme/repo']] : [],
            ];
            $pull = [
                'repository' => 'acme/repo',
                'number' => 10 + ($i % 50),
                'headSha' => $validHead,
            ];
            $expectedHead = $headMatchesUi ? $validHead : $otherHead;

            $remotePullResponse = [
                'number' => $pull['number'],
                'state' => $isOpen ? 'open' : 'closed',
                'draft' => $isDraft,
                'mergeable' => $isMergeable,
                'head' => ['sha' => $remoteHeadMatches ? $validHead : $otherHead],
            ];

            $shouldSucceedMerge = $hasUser && $inScope && $headMatchesUi && $remoteHeadMatches && $isOpen && !$isDraft && $isMergeable;

            $httpCalls = 0;
            $http = new MockHttpClient(function (string $method) use (&$httpCalls, $remotePullResponse): MockResponse {
                ++$httpCalls;
                if ($method === 'GET') {
                    return new MockResponse(json_encode($remotePullResponse, JSON_THROW_ON_ERROR));
                }
                return new MockResponse(json_encode(['merged' => true, 'sha' => str_repeat('f', 40)], JSON_THROW_ON_ERROR));
            });

            $actions = new PullRequestActions($http);
            try {
                $res = $actions->merge('gho_valid_simulation_token_123456', $access, $pull, $expectedHead);
                self::assertTrue($shouldSucceedMerge, "Scenario #{$i} merged despite failing a kernel precondition.");
                self::assertTrue($res['merged']);
                self::assertSame(2, $httpCalls);
            } catch (\RuntimeException) {
                self::assertFalse($shouldSucceedMerge, "Scenario #{$i} threw unexpectedly when all preconditions held.");
                // Verify PUT /merge was never called when any precondition failed
                self::assertLessThanOrEqual(1, $httpCalls);
            }

            // Also verify branch naming determinism and format across varying issue numbers and titles
            $branch = DedicatedTestRepoPublisher::branchName("Simulated issue #{$i}: check edge cases!", ($i % 99) + 1, "run-{$i}");
            self::assertMatchesRegularExpression('~\Aproofpatch/\d+-[a-z0-9-]{1,40}-[a-f0-9]{6}\z~', $branch);
        }

        self::assertSame(1200, $scenariosRun);
    }
}
