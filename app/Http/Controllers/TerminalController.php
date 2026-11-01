<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use ProofPatch\Support\WebTerminal;

/**
 * A session-scoped read-only web terminal. Parsing lives in WebTerminal;
 * browser requests never run local CLI commands with the server's authority.
 * Commands return a terminal exit code inside the JSON response.
 */
final class TerminalController
{
    public function __construct(private ?\Hari\Registry $hari = null)
    {
    }

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

        // The Artisan commands run as the local OS user and read global state.
        // They cannot be given the browser's authorization scope, so never call
        // them from a web request. Use the grant rechecked by the middleware.
        $access = $request->attributes->get('proofpatch_github_access');
        $userId = is_array($access) ? ($access['userId'] ?? null) : null;
        $authorized = is_array($access) && is_array($access['repositories'] ?? null) ? $access['repositories'] : [];
        $allowed = [];
        if (is_int($userId) && $userId > 0) {
            foreach ($authorized as $repository => $granted) {
                if ($granted === true && is_string($repository) && \ProofPatch\Support\KernelCli::isValidRepo($repository)) {
                    $allowed[strtolower($repository)] = true;
                }
            }
        }

        if ($parsed['command'] === 'kernel:status') {
            $connected = is_int($userId) && $userId > 0;
            $status = ['connected' => $connected, 'reposCount' => count($allowed)];
            $output = isset($parsed['params']['--json'])
                ? json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
                : 'ProofPatcher Kernel — '.($connected ? 'connected · '.count($allowed).' repositories available' : 'not connected');
            return response()->json(['output' => $output, 'exit' => 0]);
        }

        if (!is_int($userId) || $userId <= 0) {
            return response()->json(['output' => 'Connect GitHub to use this command.', 'exit' => 1]);
        }

        if ($parsed['command'] === 'kernel:select-repo') {
            $output = $allowed === [] ? 'No repositories available.' : implode("\n", array_keys($allowed));
            return response()->json(['output' => $output, 'exit' => 0]);
        }

        if ($parsed['command'] === 'kernel:telemetry') {
            return response()->json(['output' => 'Usage details are available in the local terminal.', 'exit' => 1]);
        }

        if ($parsed['command'] === 'kernel:reviews') {
            $repository = strtolower((string) ($parsed['params']['--repository'] ?? ''));
            if ($repository !== '' && !isset($allowed[$repository])) {
                return response()->json(['output' => 'Repository is not available to this GitHub connection.', 'exit' => 1]);
            }
            if ($allowed === []) {
                return response()->json(['output' => 'No automatic review findings available.', 'exit' => 0]);
            }

            try {
                $input = ['limit' => min(5000, max(1, (int) ($parsed['params']['--limit'] ?? 10)))];
                $validRepo = static fn (mixed $candidate): bool =>
                    is_string($candidate) && preg_match('~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}\z~', $candidate) === 1;
                if ($repository !== '') {
                    if (!$validRepo($repository)) {
                        return response()->json(['output' => 'No automatic review findings available.', 'exit' => 0]);
                    }
                    $input['repository'] = $repository;
                } else {
                    $repos = array_values(array_filter(array_keys($allowed), $validRepo));
                    if ($repos === []) {
                        return response()->json(['output' => 'No automatic review findings available.', 'exit' => 0]);
                    }
                    $input['repositories'] = $repos;
                }
                if (isset($parsed['params']['--status'])) {
                    $status = (string) $parsed['params']['--status'];
                    if (!in_array($status, \ProofPatch\Abilities\ReviewResultSkill::STATUSES, true)) {
                        return response()->json(['output' => 'No automatic review findings available.', 'exit' => 0]);
                    }
                    $input['status'] = $status;
                }
                $outcome = ($this->hari ?? \Hari\Hari::registry())
                    ->resolve('review.result', 1, 'web')
                    ->invoke($input);
                $rows = is_array($outcome['rows'] ?? null) ? $outcome['rows'] : [];
                $lines = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lines[] = ($row['repository'] ?? '?').'#'.($row['pull_number'] ?? '?').' · '.($row['status'] ?? '?').' · '.(string) ($row['summary'] ?? '');
                }
                $output = $lines === [] ? 'No automatic review findings available.' : implode("\n", $lines);
                if (strlen($output) > WebTerminal::MAX_OUTPUT) {
                    $output = substr($output, 0, WebTerminal::MAX_OUTPUT)."\n…output truncated…";
                }
                return response()->json(['output' => $output, 'exit' => 0]);
            } catch (\Throwable) {
                return response()->json(['output' => 'Could not load review findings.', 'exit' => 1]);
            }
        }

        return response()->json(['output' => 'Command unavailable.', 'exit' => 1]);
    }
}
