<?php

declare(strict_types=1);

namespace ProofPatch\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use ProofPatch\Mcp\Tools\BudgetStatusTool;
use ProofPatch\Mcp\Tools\EvidenceVerifyTool;
use ProofPatch\Mcp\Tools\ReviewResultTool;

#[Name('ProofPatcher')]
#[Version('0.2.0')]
#[Instructions('Read-only local inspection of the ProofPatcher review pipeline: budgets, review outcomes, and evidence receipts. Never proposes, verifies, publishes, or touches GitHub.')]
final class ProofPatchServer extends Server
{
    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        BudgetStatusTool::class,
        ReviewResultTool::class,
        EvidenceVerifyTool::class,
    ];

    /** @var array<int, class-string<\Laravel\Mcp\Server\Resource>> */
    protected array $resources = [];

    /** @var array<int, class-string<\Laravel\Mcp\Server\Prompt>> */
    protected array $prompts = [];
}
