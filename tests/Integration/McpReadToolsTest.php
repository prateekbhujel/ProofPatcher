<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Hari\Registry;
use Laravel\Mcp\Request as McpRequest;
use PHPUnit\Framework\TestCase;
use ProofPatch\Abilities\HariBoot;
use ProofPatch\Mcp\Tools\EvidenceVerifyTool;
use ProofPatch\Mcp\Tools\ReviewResultTool;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class McpReadToolsTest extends TestCase
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

    public function testReviewToolListsRows(): void
    {
        AutoReviewResult::create([
            'repository' => 'acme/demo', 'pull_number' => 5,
            'head_sha' => str_repeat('a', 40), 'base_sha' => str_repeat('b', 40),
            'status' => 'completed', 'is_public' => true, 'summary' => 'Fine.',
            'model' => 'test-model',
        ]);

        $response = (new ReviewResultTool(HariBoot::boot()))->handle(new McpRequest(['limit' => 5]));

        self::assertFalse($response->isError());
        $data = json_decode((string) (($response->content()->toArray())['text'] ?? ''), true);
        self::assertSame('list', $data['mode']);
        self::assertCount(1, $data['rows']);
        self::assertSame('acme/demo', $data['rows'][0]['repository']);
    }

    public function testReviewToolFailsClosedOnUnbootedRegistry(): void
    {
        $response = (new ReviewResultTool(new Registry()))->handle(new McpRequest([]));

        self::assertTrue($response->isError());
    }

    public function testEvidenceToolRequiresDirectory(): void
    {
        $response = (new EvidenceVerifyTool(HariBoot::boot()))->handle(new McpRequest([]));

        self::assertTrue($response->isError());
    }
}
