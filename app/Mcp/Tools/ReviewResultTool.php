<?php

declare(strict_types=1);

namespace ProofPatch\Mcp\Tools;

use Hari\Hari;
use Hari\Registry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read automatic review outcomes: one exact revision or a filtered recent list. Read-only; local data only.')]
final class ReviewResultTool extends Tool
{
    public function __construct(private ?Registry $hari = null)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $input = [];
            foreach (['repository', 'status'] as $key) {
                $value = $request->get($key);
                if (is_string($value) && $value !== '') {
                    $input[$key] = $value;
                }
            }
            foreach (['pull_number', 'limit'] as $key) {
                $value = $request->get($key);
                if (is_int($value)) {
                    $input[$key] = $value;
                }
            }
            foreach (['head_sha', 'base_sha'] as $key) {
                $value = $request->get($key);
                if (is_string($value) && $value !== '') {
                    $input[$key] = $value;
                }
            }
            $result = ($this->hari ?? Hari::registry())->resolve('review.result', 1, 'mcp')->invoke($input);
        } catch (\Throwable) {
            return Response::error('Review outcomes are unavailable.');
        }
        if (!is_array($result)) {
            return Response::error('Review outcomes are unavailable.');
        }
        return Response::json($result);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('owner/repo filter.')->nullable(),
            'pull_number' => $schema->integer()->description('Pull request number filter.')->nullable(),
            'head_sha' => $schema->string()->description('40-hex head SHA (exact mode needs all four revision keys).')->nullable(),
            'base_sha' => $schema->string()->description('40-hex base SHA.')->nullable(),
            'status' => $schema->string()->description('One of: completed, failed, skipped_private, skipped_budget, skipped_opted_out.')->nullable(),
            'limit' => $schema->integer()->description('Rows to return, 1-5000. Default 10.')->nullable(),
        ];
    }
}
