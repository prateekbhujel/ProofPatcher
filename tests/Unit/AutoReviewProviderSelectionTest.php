<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\ProviderConfigStore;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AutoReviewProviderSelectionTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempDir;
    private string $tempBudgetDir;
    private RequestBudget $budget;
    private ?string $oldProviderOverride = null;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();

        $this->tempDir = sys_get_temp_dir().'/proofpatch_auto_review_provider_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->tempBudgetDir = $this->tempDir.'/budget';
        mkdir($this->tempBudgetDir, 0700, true);
        $this->budget = new RequestBudget($this->tempBudgetDir, 500000, 10);

        $this->oldProviderOverride = getenv('PROOFPATCH_PROVIDER_CONFIG_FILE') !== false
            ? (string) getenv('PROOFPATCH_PROVIDER_CONFIG_FILE')
            : null;
        putenv('PROOFPATCH_PROVIDER_CONFIG_FILE='.$this->tempDir.'/provider_config.enc');
    }

    protected function tearDown(): void
    {
        if ($this->oldProviderOverride !== null) {
            putenv('PROOFPATCH_PROVIDER_CONFIG_FILE='.$this->oldProviderOverride);
        } else {
            putenv('PROOFPATCH_PROVIDER_CONFIG_FILE');
        }

        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_file($this->tempBudgetDir.'/runtime-ledger.json')) {
            @unlink($this->tempBudgetDir.'/runtime-ledger.json');
        }
        if (is_dir($this->tempBudgetDir)) {
            @rmdir($this->tempBudgetDir);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }

        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testAutomaticReviewUsesConfiguredMetaProvider(): void
    {
        ProviderConfigStore::selectActive('meta', 'muse-spark-1.3');
        ProviderConfigStore::saveKey('meta', str_repeat('m', 32));

        $target = ProviderConfigStore::activeTarget();
        $this->assertSame('meta', $target['provider']);
        $this->assertSame('muse-spark-1.3', $target['model']);

        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $captured = [];
        $modelHttp = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['method'] = $method;
            $captured['url'] = $url;
            $captured['body'] = json_decode((string) ($options['body'] ?? ''), true);

            $modelContent = json_encode([
                'summary' => 'Meta provider advisory review.',
                'riskLevel' => 'medium',
                'recommendation' => 'discuss',
                'fileFindings' => [
                    [
                        'path' => 'src/Calculator.php',
                        'status' => 'note',
                        'lineHint' => 10,
                        'comment' => 'Rounding change deserves a second look.',
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode([
                'model' => 'muse-spark-1.3',
                'choices' => [
                    ['finish_reason' => 'stop', 'message' => ['content' => $modelContent]],
                ],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 80],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new AutoReviewService(
            github: $this->mockGitHubClient(),
            patchPreviewer: $this->mockPatchPreviewer(),
            reviewAgent: new PullRequestReview($modelHttp, $this->budget),
            budget: $this->budget,
            // No explicit key override: the service must resolve the configured Meta key itself.
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['reviewsCompleted']);
        $this->assertSame('POST', $captured['method'] ?? null);
        $this->assertSame('https://api.meta.ai/v1/chat/completions', $captured['url'] ?? null);
        $this->assertSame('muse-spark-1.3', $captured['body']['model'] ?? null);

        $record = AutoReviewResult::findForRevision(
            'acme/public-repo', 42,
            '1111111111111111111111111111111111111111',
            '2222222222222222222222222222222222222222'
        );
        $this->assertNotNull($record);
        $this->assertSame('completed', $record->status);
        $this->assertSame('muse-spark-1.3', $record->model);
        $this->assertSame('local_only', $record->published_status);
        $this->assertSame('discuss', $record->recommendation);
    }

    public function testModelIdentityMismatchFailsWithoutStoringFindings(): void
    {
        ProviderConfigStore::selectActive('meta', 'muse-spark-1.3');
        ProviderConfigStore::saveKey('meta', str_repeat('m', 32));
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $modelHttp = new MockHttpClient([
            new MockResponse(json_encode([
                // Wrong model echoed back: must be rejected, never stored.
                'model' => 'meta-models/muse-spark-open-1026',
                'choices' => [
                    ['finish_reason' => 'stop', 'message' => ['content' => json_encode([
                        'summary' => 'Mismatched model review.',
                        'riskLevel' => 'low',
                        'recommendation' => 'approve',
                        'fileFindings' => [],
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
            ]), ['http_code' => 200]),
        ]);

        $service = new AutoReviewService(
            github: $this->mockGitHubClient(),
            patchPreviewer: $this->mockPatchPreviewer(),
            reviewAgent: new PullRequestReview($modelHttp, $this->budget),
            budget: $this->budget,
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsFailed']);

        $record = AutoReviewResult::findForRevision(
            'acme/public-repo', 42,
            '1111111111111111111111111111111111111111',
            '2222222222222222222222222222222222222222'
        );
        $this->assertNotNull($record);
        $this->assertSame('failed', $record->status);
        $this->assertNull($record->summary);
    }

    private function mockGitHubClient(): GitHubAppClient
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['id' => 12345, 'login' => 'maintainer'])),
            new MockResponse(json_encode(['installations' => [['id' => 101]]])),
            new MockResponse(json_encode([
                'repositories' => [
                    [
                        'id' => 100,
                        'name' => 'public-repo',
                        'full_name' => 'acme/public-repo',
                        'owner' => ['login' => 'acme'],
                        'private' => false,
                        'visibility' => 'public',
                        'default_branch' => 'main',
                    ],
                ],
            ])),
            new MockResponse(json_encode([
                [
                    'number' => 42,
                    'title' => 'Fix calculation rounding error',
                    'state' => 'open',
                    'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
                    'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
                    'user' => ['login' => 'contributor1'],
                ],
            ])),
        ]);

        return new GitHubAppClient($http);
    }

    private function mockPatchPreviewer(): PublicPatchPreview
    {
        $repoData = ['full_name' => 'acme/public-repo', 'private' => false, 'visibility' => 'public'];
        $pullData = [
            'number' => 42,
            'head' => ['sha' => '1111111111111111111111111111111111111111'],
            'base' => ['sha' => '2222222222222222222222222222222222222222'],
            'changed_files' => 1,
        ];
        $filesData = [
            [
                'filename' => 'src/Calculator.php',
                'additions' => 1,
                'deletions' => 1,
                'patch' => "@@ -10,3 +10,3 @@\n-  return round(\$val, 1);\n+  return round(\$val, 2);",
            ],
        ];

        $http = new MockHttpClient([
            new MockResponse(json_encode($repoData)),
            new MockResponse(json_encode($pullData)),
            new MockResponse(json_encode($filesData)),
            new MockResponse(json_encode($pullData)),
            new MockResponse(json_encode($repoData)),
        ]);

        return new PublicPatchPreview($http);
    }
}
