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

#[Description('Verify a local evidence receipt directory and report VALID or INVALID with the reason. Read-only.')]
final class EvidenceVerifyTool extends Tool
{
    public function __construct(private ?Registry $hari = null)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $directory = $request->get('directory');
            $expected = $request->get('expected');
            if (!is_string($directory) || $directory === '') {
                return Response::error('A receipt directory is required.');
            }
            $result = ($this->hari ?? Hari::registry())->resolve('evidence.verify', 1, 'mcp')->invoke([
                'directory' => $directory,
                'expected' => is_string($expected) ? $expected : null,
            ]);
        } catch (\Throwable) {
            return Response::error('Evidence verification is unavailable.');
        }
        if (!is_array($result)) {
            return Response::error('Evidence verification is unavailable.');
        }
        return Response::json($result);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'directory' => $schema->string()->description('Local evidence receipt directory.')->required(),
            'expected' => $schema->string()->description('Optional expected 64-hex SHA-256 digest.')->nullable(),
        ];
    }
}
