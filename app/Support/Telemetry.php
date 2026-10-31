<?php

declare(strict_types=1);

namespace ProofPatch\Support;

use ProofPatch\Models\AutoReviewResult;

/**
 * Local usage telemetry in the spirit of an LLM observability dashboard:
 * model request counts, token totals, reservation estimates, per-model and
 * per-day breakdowns, and a merged recent-activity feed.
 *
 * Sources are strictly local: the request-budget ledger file and the
 * auto-review results table. Reservations are pre-call estimates, never
 * provider bills. Latency is not recorded and is never shown.
 */
final class Telemetry
{
    public static function defaultLedgerPath(): string
    {
        $override = getenv('PROOFPATCH_LEDGER_FILE');
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $home = getenv('HOME');
        $base = is_string($home) && trim($home) !== '' ? rtrim($home, '/') : sys_get_temp_dir();

        return $base.'/.local/share/proofpatch/runtime/runtime-ledger.json';
    }

    /**
     * @return array{
     *     requests: array{total: int, received: int, uncertain: int, reserved: int, reservedMicros: int},
     *     tokens: array{prompt: int, completion: int, total: int},
     *     perDay: list<array{day: string, requests: int, micros: int}>,
     *     models: list<array{model: string, reviews: int, promptTokens: int, completionTokens: int}>,
     *     reviews: array{total: int, completed: int, failed: int, skippedPrivate: int, skippedBudget: int},
     *     feed: list<array{at: string, kind: string, status: string, detail: string, micros: ?int, tokens: ?int}>,
     *     generatedAt: string,
     *     ledgerFound: bool
     * }
     */
    public static function collect(?string $ledgerPath = null): array
    {
        $rawAttempts = self::readLedger($ledgerPath ?? self::defaultLedgerPath());
        $ledgerFound = $rawAttempts !== null;
        $attempts = $rawAttempts ?? [];

        $requests = ['total' => 0, 'received' => 0, 'uncertain' => 0, 'reserved' => 0, 'reservedMicros' => 0];
        $tokens = ['prompt' => 0, 'completion' => 0, 'total' => 0];
        $perDay = [];
        $feed = [];

        foreach ($attempts as $attempt) {
            $requests['total']++;
            $status = $attempt['status'] ?? 'unknown';
            if (isset($requests[$status])) {
                $requests[$status]++;
            }
            $micros = (int) ($attempt['reservedMicros'] ?? 0);
            $requests['reservedMicros'] += $micros;

            $at = is_string($attempt['at'] ?? null) ? $attempt['at'] : '';
            $day = substr($at, 0, 10);
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day) === 1) {
                $perDay[$day] ??= ['day' => $day, 'requests' => 0, 'micros' => 0];
                $perDay[$day]['requests']++;
                $perDay[$day]['micros'] += $micros;
            }

            $usage = $attempt['usage'] ?? null;
            $attemptTokens = null;
            if (is_array($usage) && is_int($usage['prompt_tokens'] ?? null) && is_int($usage['completion_tokens'] ?? null)) {
                $tokens['prompt'] += $usage['prompt_tokens'];
                $tokens['completion'] += $usage['completion_tokens'];
                $attemptTokens = $usage['prompt_tokens'] + $usage['completion_tokens'];
            }
            $tokens['total'] = $tokens['prompt'] + $tokens['completion'];

            $feed[] = [
                'at' => $at,
                'kind' => 'model_request',
                'status' => is_string($status) ? $status : 'unknown',
                'detail' => 'Model request reservation',
                'micros' => $micros,
                'tokens' => $attemptTokens,
            ];
        }

        $reviews = ['total' => 0, 'completed' => 0, 'failed' => 0, 'skippedPrivate' => 0, 'skippedBudget' => 0];
        $models = [];
        try {
            $rows = AutoReviewResult::orderByDesc('id')->limit(500)->get();
            foreach ($rows as $row) {
                $reviews['total']++;
                match ($row->status) {
                    'completed' => $reviews['completed']++,
                    'failed' => $reviews['failed']++,
                    'skipped_private' => $reviews['skippedPrivate']++,
                    'skipped_budget' => $reviews['skippedBudget']++,
                    default => null,
                };

                if ($row->status === 'completed') {
                    $model = is_string($row->model) && $row->model !== '' ? $row->model : 'unknown';
                    $models[$model] ??= ['model' => $model, 'reviews' => 0, 'promptTokens' => 0, 'completionTokens' => 0];
                    $models[$model]['reviews']++;
                    $usage = $row->usage;
                    if (is_array($usage) && is_int($usage['prompt_tokens'] ?? null) && is_int($usage['completion_tokens'] ?? null)) {
                        $models[$model]['promptTokens'] += $usage['prompt_tokens'];
                        $models[$model]['completionTokens'] += $usage['completion_tokens'];
                    }
                }
            }

            foreach (AutoReviewResult::orderByDesc('id')->limit(25)->get() as $row) {
                $usage = $row->usage;
                $reviewTokens = (is_array($usage) && is_int($usage['prompt_tokens'] ?? null) && is_int($usage['completion_tokens'] ?? null))
                    ? $usage['prompt_tokens'] + $usage['completion_tokens']
                    : null;
                $at = $row->reviewed_at?->toIso8601String() ?? $row->created_at?->toIso8601String() ?? '';
                $feed[] = [
                    'at' => $at,
                    'kind' => 'review',
                    'status' => (string) $row->status,
                    'detail' => $row->repository.'#'.$row->pull_number.($row->recommendation ? ' · '.str_replace('_', ' ', (string) $row->recommendation) : ''),
                    'micros' => null,
                    'tokens' => $reviewTokens,
                ];
            }
        } catch (\Throwable) {
            // Database unavailable: ledger-only telemetry still renders.
        }

        usort($feed, static fn(array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
        $feed = array_slice($feed, 0, 25);

        krsort($perDay);
        $perDay = array_slice(array_values($perDay), 0, 14);

        $models = array_values($models);
        usort($models, static fn(array $a, array $b): int => $b['reviews'] <=> $a['reviews']);

        return [
            'requests' => $requests,
            'tokens' => $tokens,
            'perDay' => $perDay,
            'models' => $models,
            'reviews' => $reviews,
            'feed' => $feed,
            'generatedAt' => gmdate(DATE_ATOM),
            'ledgerFound' => $ledgerFound,
        ];
    }

    /** @return list<array>|null null when the ledger is absent (not an error) */
    private static function readLedger(string $path): ?array
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path, false, null, 0, 65537);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded) || !is_array($decoded['attempts'] ?? null)) {
            return [];
        }

        return array_values(array_filter($decoded['attempts'], static fn(mixed $a): bool => is_array($a)));
    }
}
