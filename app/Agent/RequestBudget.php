<?php

declare(strict_types=1);

namespace ProofPatch\Agent;

/**
 * Persistent usage ledger in integer millionths of a dollar. Metering
 * only: reservations are recorded for telemetry, but no local cap ever
 * refuses a request — provider quotas (Baseten/Modal/Meta) are the
 * limit. Explicit caps remain opt-in via PROOFPATCH_MODEL_MAX_ATTEMPTS.
 */
final readonly class RequestBudget
{
    public const DEFAULT_LIMIT_MICROS = PHP_INT_MAX;
    public const DEFAULT_MAX_ATTEMPTS = PHP_INT_MAX;

    public function __construct(
        private string $directory,
        private int $limitMicros = self::DEFAULT_LIMIT_MICROS,
        private int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ) {
        if ($limitMicros <= 0 || $maxAttempts < 1) {
            throw new \InvalidArgumentException('Request budget limits must be positive.');
        }
    }

    public static function forRuntime(?string $customDir = null): self
    {
        $rawAttempts = getenv('PROOFPATCH_MODEL_MAX_ATTEMPTS');
        if ($rawAttempts === false || $rawAttempts === '') {
            $rawAttempts = $_ENV['PROOFPATCH_MODEL_MAX_ATTEMPTS'] ?? $_SERVER['PROOFPATCH_MODEL_MAX_ATTEMPTS'] ?? null;
        }
        $configuredAttempts = is_scalar($rawAttempts) ? (string) $rawAttempts : null;
        if ($configuredAttempts === null || $configuredAttempts === '') {
            $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;
        } elseif (preg_match('/\A[1-9][0-9]{0,2}\z/D', $configuredAttempts) && (int) $configuredAttempts <= 100) {
            $maxAttempts = (int) $configuredAttempts;
        } else {
            throw new \RuntimeException('Model attempt limit must be between 1 and 100.');
        }

        if ($customDir !== null) {
            return new self($customDir, self::DEFAULT_LIMIT_MICROS, $maxAttempts);
        }
        $home = getenv('HOME');
        if (!$home) {
            throw new \RuntimeException('The local request budget is unavailable.');
        }

        return new self($home.'/.local/share/proofpatch/runtime', self::DEFAULT_LIMIT_MICROS, $maxAttempts);
    }

    public function status(): array
    {
        $path = $this->directory.'/runtime-ledger.json';
        if (is_link($this->directory) || is_link($path)) {
            throw new \RuntimeException('Budget path must not be a symlink.');
        }
        if (!file_exists($path)) {
            return $this->summarize(['version' => 1, 'attempts' => []]);
        }
        $handle = fopen($path, 'r');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            throw new \RuntimeException('Cannot read the request budget.');
        }
        try {
            return $this->summarize($this->read($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function run(int $reserveMicros, callable $operation, ?callable $preflight = null): mixed
    {
        if ($reserveMicros <= 0 || $reserveMicros > $this->limitMicros) {
            throw new \RuntimeException('Request exceeds the local credit budget.');
        }
        if (is_link($this->directory) || (!is_dir($this->directory) && !mkdir($this->directory, 0700, true))) {
            throw new \RuntimeException('Cannot create private request budget directory.');
        }
        chmod($this->directory, 0700);
        $path = $this->directory.'/runtime-ledger.json';
        if (is_link($path)) {
            throw new \RuntimeException('Budget ledger must not be a symlink.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the request budget.');
        }
        chmod($path, 0600);
        try {
            $ledger = $this->read($handle);
            $status = $this->summarize($ledger);
            // Opt-in caps only: when a caller configured explicit limits
            // they still apply; the defaults never refuse.
            if ($this->maxAttempts !== PHP_INT_MAX && $status['attempts'] >= $this->maxAttempts) {
                throw new \RuntimeException('The configured request cap is reached.');
            }
            if ($preflight !== null) {
                $preflight();
            }
            $index = count($ledger['attempts']);
            $ledger['attempts'][] = ['at' => gmdate(DATE_ATOM), 'reservedMicros' => $reserveMicros, 'status' => 'reserved'];
            $this->save($handle, $ledger);
            try {
                $result = $operation();
                $ledger['attempts'][$index]['status'] = 'received';
                $ledger['attempts'][$index]['usage'] = $result['usage'];
                $this->save($handle, $ledger);
                return $result;
            } catch (\Throwable $error) {
                $ledger['attempts'][$index]['status'] = 'uncertain';
                $this->save($handle, $ledger);
                throw $error;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function read($handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle, 65537);
        if ($contents === false || strlen($contents) > 65536) {
            throw new \RuntimeException('Request ledger is unreadable or too large.');
        }
        $ledger = $contents === '' ? ['version' => 1, 'attempts' => []] : json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($ledger) || ($ledger['version'] ?? null) !== 1 || !is_array($ledger['attempts'] ?? null)
            || !array_is_list($ledger['attempts'])) {
            throw new \RuntimeException('Request ledger is invalid.');
        }
        return $ledger;
    }

    private function summarize(array $ledger): array
    {
        $reserved = 0;
        foreach ($ledger['attempts'] as $attempt) {
            if (!is_array($attempt) || !is_int($attempt['reservedMicros'] ?? null) || $attempt['reservedMicros'] <= 0
                || !in_array($attempt['status'] ?? null, ['reserved', 'received', 'uncertain'], true)
                || $attempt['reservedMicros'] > $this->limitMicros - $reserved) {
                throw new \RuntimeException('Request ledger contains an invalid reservation.');
            }
            $reserved += $attempt['reservedMicros'];
        }
        return ['attempts' => count($ledger['attempts']), 'maxAttempts' => $this->maxAttempts,
            'reservedMicros' => $reserved, 'limitMicros' => $this->limitMicros,
            'remainingMicros' => $this->limitMicros - $reserved,
            'canRequest' => count($ledger['attempts']) < $this->maxAttempts && $reserved < $this->limitMicros];
    }

    private function save($handle, array $ledger): void
    {
        $data = json_encode($ledger, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
            throw new \RuntimeException('Cannot persist the request budget.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new \RuntimeException('Cannot sync the request budget.');
        }
    }
}
