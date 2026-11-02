<?php

declare(strict_types=1);

namespace ProofPatch\Agent;

/**
 * Manages server-held cryptographic records of genuine model proposals.
 *
 * Guarantees:
 * - Stored in server-private directory (0700/0600) inaccessible to client or untrusted candidates.
 * - Bound to candidate SHA-256, baseline source SHA-256, request digest, model, and genuine provider usage.
 * - Signed with server-only HMAC key.
 * - Prevents request parameters from fabricating model provenance on offline reference repairs.
 * - Fails closed if candidate bytes, model name, or usage metrics do not match the recorded proposal.
 */
final class ServerHeldModelProposal
{
    private readonly string $storageDir;
    private ?string $serverSecret;

    public function __construct(?string $storageDir = null, ?string $serverSecret = null)
    {
        if ($storageDir !== null) {
            $this->storageDir = $storageDir;
        } else {
            $path = null;
            if (function_exists('storage_path')) {
                try {
                    $path = storage_path('app/model_proposals');
                } catch (\Throwable) {
                    $path = null;
                }
            }
            $this->storageDir = $path ?? (sys_get_temp_dir() . '/proofpatch_model_proposals');
        }
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
        $this->serverSecret = $serverSecret ?? $this->resolveServerSecret();
    }

    /**
     * Records a validated provider proposal from a supported Muse endpoint.
     */
    public function record(array $proposalData): void
    {
        $candidateSha = (string) ($proposalData['candidateSha256'] ?? '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $candidateSha)) {
            throw new \InvalidArgumentException('Valid candidate SHA-256 is required to record model proposal.');
        }

        $sourceSha = (string) ($proposalData['sourceSha256'] ?? '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $sourceSha)) {
            throw new \InvalidArgumentException('Valid source SHA-256 is required.');
        }

        $model = (string) ($proposalData['model'] ?? '');
        $models = array_merge(ProviderConfigStore::PROVIDERS['baseten']['models'], ProviderConfigStore::PROVIDERS['meta']['models']);
        if (!in_array($model, $models, true)) {
            throw new \InvalidArgumentException("Unsupported or untrusted model: '{$model}'.");
        }

        $usage = $proposalData['usage'] ?? [];
        if (!is_array($usage) || !is_int($usage['prompt_tokens'] ?? null) || !is_int($usage['completion_tokens'] ?? null)) {
            throw new \InvalidArgumentException('Valid usage metrics required.');
        }

        $requestDigest = (string) ($proposalData['requestDigest'] ?? '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $requestDigest)) {
            throw new \InvalidArgumentException('Valid request digest required.');
        }

        $candidateSource = (string) ($proposalData['candidateSource'] ?? '');
        if (hash('sha256', $candidateSource) !== $candidateSha) {
            throw new \InvalidArgumentException('Candidate source does not match provided candidate SHA-256.');
        }

        $find = (string) ($proposalData['find'] ?? '');
        $replace = (string) ($proposalData['replace'] ?? '');
        $explanation = (string) ($proposalData['explanation'] ?? '');
        $githubUserId = (int) ($proposalData['githubUserId'] ?? 0);
        $repository = (string) ($proposalData['repository'] ?? '');
        $issueNumber = (int) ($proposalData['issueNumber'] ?? 0);
        $path = (string) ($proposalData['path'] ?? '');

        $now = time();
        $payload = implode(':', [
            $candidateSha,
            $sourceSha,
            $model,
            $requestDigest,
            (string) $usage['prompt_tokens'],
            (string) $usage['completion_tokens'],
            hash('sha256', $find),
            hash('sha256', $replace),
            hash('sha256', $explanation),
            hash('sha256', $candidateSource),
            (string) $githubUserId,
            $repository,
            (string) $issueNumber,
            $path,
            (string) $now,
        ]);
        $hmac = hash_hmac('sha256', $payload, $this->serverSecret);

        $record = [
            'candidateSha256' => $candidateSha,
            'sourceSha256' => $sourceSha,
            'model' => $model,
            'requestDigest' => $requestDigest,
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
            ],
            'find' => $find,
            'replace' => $replace,
            'explanation' => $explanation,
            'candidateSource' => $candidateSource,
            'githubUserId' => $githubUserId,
            'repository' => $repository,
            'issueNumber' => $issueNumber,
            'path' => $path,
            'recordedAt' => $now,
            'hmac' => $hmac,
        ];

        $filePath = $this->storageDir . '/' . $candidateSha . '.json';
        file_put_contents($filePath, json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($filePath, 0600);
    }

    /**
     * Looks up and cryptographically verifies a server-held model proposal by candidate SHA.
     */
    public function lookup(string $candidateSha, ?array $expectedContext = null): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $candidateSha)) {
            return null;
        }

        $filePath = $this->storageDir . '/' . $candidateSha . '.json';
        if (!is_file($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        try {
            $record = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($record)) {
            return null;
        }

        $recordedCandidateSha = (string) ($record['candidateSha256'] ?? '');
        $candidateSource = (string) ($record['candidateSource'] ?? '');
        if (!hash_equals($candidateSha, $recordedCandidateSha) || hash('sha256', $candidateSource) !== $candidateSha) {
            return null;
        }

        $payload = implode(':', [
            $recordedCandidateSha,
            (string) ($record['sourceSha256'] ?? ''),
            (string) ($record['model'] ?? ''),
            (string) ($record['requestDigest'] ?? ''),
            (string) ($record['usage']['prompt_tokens'] ?? ''),
            (string) ($record['usage']['completion_tokens'] ?? ''),
            hash('sha256', (string) ($record['find'] ?? '')),
            hash('sha256', (string) ($record['replace'] ?? '')),
            hash('sha256', (string) ($record['explanation'] ?? '')),
            hash('sha256', $candidateSource),
            (string) ($record['githubUserId'] ?? 0),
            (string) ($record['repository'] ?? ''),
            (string) ($record['issueNumber'] ?? 0),
            (string) ($record['path'] ?? ''),
            (string) ($record['recordedAt'] ?? ''),
        ]);

        $expectedHmac = hash_hmac('sha256', $payload, $this->serverSecret);
        if (!hash_equals($expectedHmac, (string) ($record['hmac'] ?? ''))) {
            return null;
        }

        if ($expectedContext !== null) {
            $expectedSourceSha = (string) ($expectedContext['sourceSha256'] ?? '');
            if ($expectedSourceSha !== '' && !hash_equals($expectedSourceSha, (string) ($record['sourceSha256'] ?? ''))) {
                return null;
            }
            $expectedRepo = (string) ($expectedContext['repository'] ?? '');
            $recordRepo = (string) ($record['repository'] ?? '');
            if ($expectedRepo !== '' && $recordRepo !== '' && strcasecmp($expectedRepo, $recordRepo) !== 0) {
                return null;
            }
        }

        return $record;
    }

    private function resolveServerSecret(): string
    {
        $appKey = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? null);
        if (is_string($appKey) && strlen($appKey) >= 16) {
            return hash('sha256', $appKey);
        }

        $keyFile = $this->storageDir . '/.server_secret';
        if (is_file($keyFile)) {
            $key = (string) file_get_contents($keyFile);
            if (strlen(trim($key)) === 64) {
                return trim($key);
            }
        }

        $newKey = bin2hex(random_bytes(32));
        file_put_contents($keyFile, $newKey, LOCK_EX);
        @chmod($keyFile, 0600);
        return $newKey;
    }
}
