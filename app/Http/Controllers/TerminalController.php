<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use ProofPatch\Support\WebTerminal;

/**
 * The kernel shell over HTTP: runs allowlisted read-only kernel commands and
 * returns their plain-text output. Parsing lives in WebTerminal (never a
 * shell); only its exact command+params run here. Always answers 200 with an
 * exit code inside: an unknown command is terminal output, not an HTTP error.
 */
final class TerminalController
{
    public function show(): InertiaResponse
    {
        return Inertia::render('Terminal');
    }

    public function run(Request $request): JsonResponse
    {
        $parsed = WebTerminal::parse((string) $request->input('command', ''));
        if ($parsed['kind'] === 'help') {
            return response()->json(['output' => WebTerminal::HELP, 'exit' => 0]);
        }
        if ($parsed['kind'] === 'error') {
            return response()->json(['output' => $parsed['message'], 'exit' => $parsed['message'] === '' ? 0 : 1]);
        }

        try {
            $exit = Artisan::call($parsed['command'], $parsed['params']);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return response()->json(['output' => 'command failed: ' . $e->getMessage(), 'exit' => 1]);
        }

        if (!is_string($output)) {
            $output = '';
        }
        if (strlen($output) > WebTerminal::MAX_OUTPUT) {
            $output = substr($output, 0, WebTerminal::MAX_OUTPUT) . "\n…output truncated…";
        }

        return response()->json(['output' => $output, 'exit' => (int) $exit]);
    }
}
