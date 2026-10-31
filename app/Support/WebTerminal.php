<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/**
 * Parses web-terminal input into allowlisted kernel command runs. The web
 * terminal is read-only by design: status, findings, telemetry, and repo
 * listing only. Anything that spends budget, writes to GitHub, or changes
 * settings stays in the real terminal and the app pages behind their own
 * confirmations. Pure and unit-tested; the controller only executes.
 */
final class WebTerminal
{
    public const MAX_INPUT = 500;

    public const MAX_OUTPUT = 51200;

    public const HELP = <<<'TEXT'
        kernel web terminal — read-only. Commands:
          status [--json]                 pipeline dashboard
          reviews [--limit N] [--repository owner/repo] [--status S]
                                          recent review findings (S: completed, failed, skipped_private, skipped_budget)
          telemetry                       local usage numbers
          repos                           authorized repositories
          help                            this list
        TEXT;

    /**
     * @return array{kind: 'help'}|array{kind: 'run', command: string, params: array<string, mixed>}|array{kind: 'error', message: string}
     */
    public static function parse(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['kind' => 'error', 'message' => ''];
        }
        if (strlen($input) > self::MAX_INPUT) {
            return ['kind' => 'error', 'message' => 'command too long (max ' . self::MAX_INPUT . ' chars)'];
        }
        if (preg_match('/[\r\n;&|`$()<>\\\\!{}\[\]#~*?\'"“”]/', $input) === 1) {
            return ['kind' => 'error', 'message' => "rejected: shell characters are not allowed — type 'help'"];
        }

        $tokens = preg_split('/\s+/', $input);
        if (!is_array($tokens) || $tokens === [] || !is_string($tokens[0]) || $tokens[0] === '') {
            return ['kind' => 'error', 'message' => ''];
        }
        $name = strtolower($tokens[0]);
        $args = array_slice($tokens, 1);

        return match ($name) {
            'help' => self::parseNoArgs($name, $args, ['kind' => 'help']),
            'status' => self::parseStatus($args),
            'reviews' => self::parseReviews($args),
            'telemetry' => self::parseNoArgs($name, $args, ['kind' => 'run', 'command' => 'kernel:telemetry', 'params' => []]),
            'repos' => self::parseNoArgs($name, $args, ['kind' => 'run', 'command' => 'kernel:select-repo', 'params' => ['--list' => true]]),
            default => ['kind' => 'error', 'message' => "unknown command '{$tokens[0]}' — type 'help'"],
        };
    }

    /**
     * @param list<string> $args
     * @param array{kind: 'help'}|array{kind: 'run', command: string, params: array<string, mixed>} $ok
     * @return array{kind: 'help'}|array{kind: 'run', command: string, params: array<string, mixed>}|array{kind: 'error', message: string}
     */
    private static function parseNoArgs(string $name, array $args, array $ok): array
    {
        if ($args !== []) {
            return ['kind' => 'error', 'message' => "'{$name}' takes no arguments — type 'help'"];
        }

        return $ok;
    }

    /** @param list<string> $args @return array{kind: 'run', command: string, params: array<string, mixed>}|array{kind: 'error', message: string} */
    private static function parseStatus(array $args): array
    {
        $params = [];
        foreach ($args as $arg) {
            if ($arg === '--json') {
                $params['--json'] = true;
                continue;
            }

            return ['kind' => 'error', 'message' => "unknown option '{$arg}' for status"];
        }

        return ['kind' => 'run', 'command' => 'kernel:status', 'params' => $params];
    }

    /** @param list<string> $args @return array{kind: 'run', command: string, params: array<string, mixed>}|array{kind: 'error', message: string} */
    private static function parseReviews(array $args): array
    {
        $params = [];
        $i = 0;
        $count = count($args);
        while ($i < $count) {
            $arg = $args[$i];
            if ($arg === '--limit' || $arg === '--repository' || $arg === '--status') {
                $value = $args[$i + 1] ?? null;
                if ($value === null || $value === '') {
                    return ['kind' => 'error', 'message' => "'{$arg}' needs a value"];
                }
                $check = self::checkReviewOption($arg, $value);
                if ($check !== null) {
                    return ['kind' => 'error', 'message' => $check];
                }
                $params[$arg] = $value;
                $i += 2;
                continue;
            }
            if (str_starts_with($arg, '--limit=')) {
                $value = substr($arg, strlen('--limit='));
                $check = self::checkReviewOption('--limit', $value);
                if ($check !== null) {
                    return ['kind' => 'error', 'message' => $check];
                }
                $params['--limit'] = $value;
                $i++;
                continue;
            }
            if (str_starts_with($arg, '--repository=')) {
                $value = substr($arg, strlen('--repository='));
                $check = self::checkReviewOption('--repository', $value);
                if ($check !== null) {
                    return ['kind' => 'error', 'message' => $check];
                }
                $params['--repository'] = $value;
                $i++;
                continue;
            }
            if (str_starts_with($arg, '--status=')) {
                $value = substr($arg, strlen('--status='));
                $check = self::checkReviewOption('--status', $value);
                if ($check !== null) {
                    return ['kind' => 'error', 'message' => $check];
                }
                $params['--status'] = $value;
                $i++;
                continue;
            }

            return ['kind' => 'error', 'message' => "unknown option '{$arg}' for reviews"];
        }

        return ['kind' => 'run', 'command' => 'kernel:reviews', 'params' => $params];
    }

    private static function checkReviewOption(string $option, string $value): ?string
    {
        if ($option === '--limit') {
            if (preg_match('/\A\d{1,2}\z/', $value) !== 1 || (int) $value < 1 || (int) $value > 50) {
                return "'--limit' needs a number from 1 to 50";
            }

            return null;
        }
        if ($option === '--repository') {
            if (!KernelCli::isValidRepo($value)) {
                return "'--repository' needs owner/repo form";
            }

            return null;
        }
        if (!in_array($value, ['completed', 'failed', 'skipped_private', 'skipped_budget'], true)) {
            return "'--status' needs completed, failed, skipped_private, or skipped_budget";
        }

        return null;
    }
}
