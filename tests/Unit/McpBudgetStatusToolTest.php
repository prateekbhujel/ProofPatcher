<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Hari\Registry;
use Laravel\Mcp\Request as McpRequest;
use PHPUnit\Framework\TestCase;
use ProofPatch\Abilities\HariBoot;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Mcp\Tools\BudgetStatusTool;

final class McpBudgetStatusToolTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/proofpatch-mcp-budget-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory.'/runtime-ledger.json', $this->directory] as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
    }

    private function bootedTool(RequestBudget $budget): BudgetStatusTool
    {
        return new BudgetStatusTool(HariBoot::boot(null, $budget));
    }

    public function testReportsExactBudgetCounters(): void
    {
        $budget = new RequestBudget($this->directory, 50000, 3);
        $budget->run(20000, static fn (): array => ['usage' => []]);

        $response = $this->bootedTool($budget)->handle(new McpRequest([]));

        self::assertFalse($response->isError());
        $payload = $response->content()->toArray();
        self::assertSame('text', $payload['type'] ?? null);
        $data = json_decode((string) ($payload['text'] ?? ''), true);
        self::assertSame([
            'attempts' => 1,
            'maxAttempts' => 3,
            'reservedMicros' => 20000,
            'limitMicros' => 50000,
            'remainingMicros' => 30000,
            'canRequest' => true,
        ], $data);
    }

    public function testPayloadCarriesNoSecretsOrPaths(): void
    {
        $response = $this->bootedTool(new RequestBudget($this->directory, 50000, 3))
            ->handle(new McpRequest([]));

        $text = (string) (($response->content()->toArray())['text'] ?? '');
        self::assertDoesNotMatchRegularExpression('/key|token|secret|password|bearer|home|tmp/i', $text);
        self::assertStringNotContainsString($this->directory, $text);
    }

    public function testFailsClosedWithoutLeakingPaths(): void
    {
        $link = $this->directory.'-link';
        @symlink(sys_get_temp_dir(), $link);
        $this->directory = $link;

        $response = $this->bootedTool(new RequestBudget($link, 50000, 3))
            ->handle(new McpRequest([]));

        self::assertTrue($response->isError());
        $text = (string) (($response->content()->toArray())['text'] ?? '');
        self::assertSame('The local request budget is unavailable.', $text);
    }

    public function testFailsClosedOnUnbootedRegistry(): void
    {
        $response = (new BudgetStatusTool(new Registry()))->handle(new McpRequest([]));

        self::assertTrue($response->isError());
    }
}
