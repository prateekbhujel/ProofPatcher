<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

/** Single-use authorization for a draft based on finite fixture observations.
 * This is deliberately separate from a verified-publication approval.
 */
final readonly class ObservedDraftApproval
{
    public function __construct(private string $directory, private string $secret, private int $ttlSeconds = 900)
    {
        if (strlen($secret) < 32 || $ttlSeconds < 1 || $ttlSeconds > 3600) throw new \InvalidArgumentException('Invalid approval configuration.');
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) || !chmod($directory, 0700)) {
            throw new \RuntimeException('Cannot protect observed draft approvals.');
        }
    }

    public static function forRuntime(): self
    {
        $key = (string) config('app.key');
        if (strlen($key) < 16) throw new \RuntimeException('Application key is required.');
        return new self(storage_path('app/observed_draft_approvals'), hash_hmac('sha256', 'proofpatch-observed-draft-v1', $key, true));
    }

    /** Only a trusted server-side runner result may be passed here, never a client-supplied report. */
    public function issue(int $userId, array $context, array $suggestion, array $observation): string
    {
        $binding = self::binding($userId, $context, $suggestion);
        if (($observation['provider'] ?? null) !== 'modal' || ($observation['scope'] ?? null) !== 'finite_local_fixture'
            || ($observation['status'] ?? null) !== 'passed'
            || ($observation['baseline'] ?? null) !== 'fixture_failed' || ($observation['negative'] ?? null) !== 'fixture_failed'
            || ($observation['candidate'] ?? null) !== 'fixture_passed'
            || ($observation['calls'] ?? null) !== ['baseline' => 3, 'negative' => 3, 'candidate' => 3]
            || ($observation['candidateSha256'] ?? null) !== $binding['candidateSha256']
            || ($observation['harnessSha256'] ?? null) !== \ProofPatch\Verifier\OwnedCaseVerifier::KNOWN_TEST_SHA256
            || ($observation['baselineSha256'] ?? null) !== DedicatedTestRepoPublisher::KNOWN_BASE_SHA256) {
            throw new \InvalidArgumentException('A completed live fixture observation is required.');
        }
        $token = bin2hex(random_bytes(32));
        $record = ['binding' => $binding, 'expiresAt' => time() + $this->ttlSeconds];
        $payload = json_encode($record, JSON_THROW_ON_ERROR);
        $record['signature'] = hash_hmac('sha256', $token."\n".$payload, $this->secret);
        $handle = @fopen($this->path($token), 'x');
        if (!$handle) throw new \RuntimeException('Cannot create observed draft approval.');
        try {
            $encoded = json_encode($record, JSON_THROW_ON_ERROR);
            if (!chmod($this->path($token), 0600) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Cannot save observed draft approval.');
            }
        } finally { fclose($handle); }
        return $token;
    }

    /** Consume once; failures leave the token consumed to prevent duplicate writes. */
    public function consume(string $token, int $userId, array $context, array $suggestion): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) throw new \InvalidArgumentException('Invalid observed draft approval.');
        $path = $this->path($token);
        $claimed = $path.'.'.bin2hex(random_bytes(8)).'.used';
        if (!@rename($path, $claimed)) throw new \RuntimeException('Observed draft approval is expired or already used.');
        try {
            $record = json_decode((string) file_get_contents($claimed), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($record) || !is_array($record['binding'] ?? null) || !is_int($record['expiresAt'] ?? null)
                || $record['expiresAt'] < time() || !is_string($record['signature'] ?? null)) throw new \RuntimeException('Observed draft approval is invalid or expired.');
            $payload = json_encode(['binding' => $record['binding'], 'expiresAt' => $record['expiresAt']], JSON_THROW_ON_ERROR);
            if (!hash_equals(hash_hmac('sha256', $token."\n".$payload, $this->secret), $record['signature'])
                || $record['binding'] !== self::binding($userId, $context, $suggestion)) {
                throw new \RuntimeException('Observed draft approval does not match this task.');
            }
            return $record['binding'];
        } finally { @unlink($claimed); }
    }

    private function path(string $token): string { return $this->directory.'/'.$token.'.json'; }

    private static function binding(int $userId, array $context, array $suggestion): array
    {
        $source = $suggestion['candidate']['source'] ?? null;
        $runId = $suggestion['privateProvenanceId'] ?? null;
        $model = $suggestion['model'] ?? null;
        $digest = $suggestion['requestDigest'] ?? null;
        if ($userId < 1 || ($context['repository'] ?? null) !== DedicatedTestRepo::requireName()
            || ($context['issueNumber'] ?? null) !== DedicatedTestRepoFetcher::ISSUE_NUMBER
            || ($context['commit'] ?? null) !== DedicatedTestRepo::requireBaseCommit()
            || ($context['path'] ?? null) !== DedicatedTestRepoFetcher::SOURCE_PATH
            || ($context['sourceSha256'] ?? null) !== DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256
            || !is_string($context['title'] ?? null) || !is_string($context['body'] ?? null)
            || !is_string($source) || $source === '' || strlen($source) > 65536
            || !is_string($runId) || !preg_match('/\A[a-f0-9]{32}\z/', $runId)
            || ($suggestion['privateProvenanceRecorded'] ?? null) !== true
            || !is_string($model) || $model === '' || strlen($model) > 128
            || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
            throw new \InvalidArgumentException('The private dedicated proposal cannot be bound.');
        }
        return ['userId' => $userId, 'repository' => $context['repository'], 'issueNumber' => $context['issueNumber'],
            'baseCommit' => $context['commit'], 'path' => $context['path'], 'sourceSha256' => $context['sourceSha256'],
            'issueDigest' => hash('sha256', json_encode([$context['title'], $context['body']], JSON_THROW_ON_ERROR)),
            'candidateSha256' => hash('sha256', $source), 'privateProvenanceId' => $runId,
            'model' => $model, 'requestDigest' => $digest];
    }
}
