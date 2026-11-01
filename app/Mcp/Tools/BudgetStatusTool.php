<?php

declare(strict_types=1);

namespace ProofPatch\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Hari\Hari;
use Hari\Registry;

#[Description('Read the local model-request budget: attempts used, cap, spend in micros, and whether another request is allowed. Read-only; reveals no keys or tokens.')]
final class BudgetStatusTool extends Tool
{
    public function __construct(private ?Registry $hari = null)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $status = ($this->hari ?? Hari::registry())
                ->resolve('budget.status', 1, 'mcp')
                ->invoke([]);
        } catch (\Throwable) {
            return Response::error('The local request budget is unavailable.');
        }

        if (!is_array($status)) {
            return Response::error('The local request budget is unavailable.');
        }
        return Response::json($status);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
