<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Hari\HariError;
use PHPUnit\Framework\TestCase;
use ProofPatch\Abilities\ReviewResultSkill;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class ReviewResultSkillTest extends TestCase
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

    private function seed(string $repository, int $number, string $status, string $head): void
    {
        AutoReviewResult::create([
            'repository' => $repository,
            'pull_number' => $number,
            'head_sha' => $head,
            'base_sha' => str_repeat('e', 40),
            'status' => $status,
            'is_public' => true,
            'summary' => 'Summary '.$number,
            'risk_level' => 'low',
            'recommendation' => 'approve',
            'file_findings' => [['path' => 'a.php', 'lineHint' => 1, 'status' => 'note', 'comment' => 'c']],
            'raw_review' => ['ok' => true],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'published_status' => 'local_only',
            'model' => 'test-model',
        ]);
    }

    public function testExactModeFindsRevision(): void
    {
        $this->seed('acme/demo', 7, 'completed', str_repeat('a', 40));

        $result = (new ReviewResultSkill())->invoke([
            'repository' => 'acme/demo',
            'pull_number' => 7,
            'head_sha' => str_repeat('a', 40),
            'base_sha' => str_repeat('e', 40),
        ]);

        self::assertSame('exact', $result['mode']);
        self::assertTrue($result['found']);
        self::assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        self::assertSame('acme/demo', $row['repository']);
        self::assertSame(1, $row['findings_count']);
        self::assertSame(['ok' => true], $row['raw_review']);
        self::assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5], $row['usage']);
    }

    public function testExactModeMissesCleanly(): void
    {
        $result = (new ReviewResultSkill())->invoke([
            'repository' => 'acme/demo',
            'pull_number' => 9,
            'head_sha' => str_repeat('a', 40),
            'base_sha' => str_repeat('e', 40),
        ]);

        self::assertSame('exact', $result['mode']);
        self::assertFalse($result['found']);
        self::assertSame([], $result['rows']);
    }

    public function testListModeFiltersAndLimits(): void
    {
        $this->seed('acme/demo', 1, 'completed', str_repeat('1', 40));
        $this->seed('acme/demo', 2, 'failed', str_repeat('2', 40));
        $this->seed('acme/other', 3, 'completed', str_repeat('3', 40));

        $result = (new ReviewResultSkill())->invoke([
            'repositories' => ['acme/demo'],
            'status' => 'completed',
            'limit' => 10,
        ]);

        self::assertSame('list', $result['mode']);
        self::assertCount(1, $result['rows']);
        self::assertSame(1, $result['rows'][0]['pull_number']);
    }

    public function testStatsModeCounts(): void
    {
        $this->seed('acme/demo', 1, 'completed', str_repeat('1', 40));
        $this->seed('acme/demo', 2, 'failed', str_repeat('2', 40));
        $this->seed('acme/other', 3, 'completed', str_repeat('3', 40));

        $result = (new ReviewResultSkill())->invoke(['stats_for' => ['acme/demo'], 'match' => 'lower']);

        self::assertSame('stats', $result['mode']);
        self::assertSame([
            'total' => 2, 'completed' => 1, 'failed' => 1,
            'skipped_private' => 0, 'skipped_budget' => 0,
        ], $result['stats']);
    }

    public function testInvalidInputsThrow(): void
    {
        $skill = new ReviewResultSkill();
        $cases = [
            ['repository' => 'not a repo'],
            ['pull_number' => 0],
            ['status' => 'whatever'],
            ['limit' => 0],
            ['limit' => 5001],
            ['stats_for' => []],
            ['stats_for' => ['acme/demo'], 'match' => 'fuzzy'],
            ['repository' => 'acme/demo', 'pull_number' => 1, 'head_sha' => 'short', 'base_sha' => str_repeat('e', 40)],
        ];
        foreach ($cases as $index => $input) {
            try {
                $skill->invoke($input);
                self::fail("Invalid input accepted at case {$index}.");
            } catch (HariError $error) {
                self::assertSame('invalid_input', $error->code(), "Case {$index}");
            }
        }
    }
}
