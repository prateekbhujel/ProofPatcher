<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

/**
 * Manages server-held, single-use cryptographic authorizations for pull request publication.
 *
 * Security guarantees:
 * - Held outside caller-accessible artifacts directory in private server storage (0700/0600).
 * - Cryptographically signed using a server-only HMAC key.
 * - Bound to exact run ID, receipt digest, base commit, repository, issue, file path, and candidate/test SHA-256 digests.
 * - Atomic single-use consumption via POSIX rename: completely prevents race conditions and duplicate spends.
 * - Explicitly rejects simulated or mocked execution runs from ever being published.
 * - Prevents an attacker who controls artifacts/runs/ from forging result.json and receipt.json.
 */
final class ServerHeldPublicationApproval
{
    public const TOKEN_LENGTH = 64; // 32 bytes hex
    public const DEFAULT_TTL_SECONDS = 900; // 15 minutes

    public function __construct(
        private readonly string $storageDir,
        private ?string $serverSecret = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
        if ($this->serverSecret === null) {
            $this->serverSecret = $this->resolveServerSecret();
        }
    }

    /**
     * Issues an ephemeral, server-held publication authorization token.
     * MUST only be called by the server-controlled live runner upon completing a genuine verification.
     */
    public function issue(
        string $runId,
        string $evidenceDirectory,
        string $receiptSha256,
        string $baseCommit,
        string $repository,
        int $issueNumber,
        string $filePath,
        string $baseSha256,
        string $candidateSha256,
        string $testSha256,
        bool $isSimulated = false,
        ?string $privateProvenanceId = null
    ): string {
        if ($privateProvenanceId !== null && !preg_match('/\A[a-f0-9]{32}\z/', $privateProvenanceId)) {
            throw new \InvalidArgumentException('Invalid private proposal run identifier.');
        }
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expiresAt = $now + $this->ttlSeconds;
        $resolvedEvidenceDir = realpath($evidenceDirectory) ?: $evidenceDirectory;

        $canonicalPayload = $this->buildPayloadString(
            $token,
            $runId,
            $resolvedEvidenceDir,
            $receiptSha256,
            $baseCommit,
            $repository,
            $issueNumber,
            $filePath,
            $baseSha256,
            $candidateSha256,
            $testSha256,
            $isSimulated,
            $privateProvenanceId
        );

        $hmac = hash_hmac('sha256', $canonicalPayload, $this->serverSecret);

        $record = [
            'token' => $token,
            'runId' => $runId,
            'evidenceDirectory' => $resolvedEvidenceDir,
            'receiptSha256' => $receiptSha256,
            'baseCommit' => $baseCommit,
            'repository' => $repository,
            'issueNumber' => $issueNumber,
            'filePath' => $filePath,
            'baseSha256' => $baseSha256,
            'candidateSha256' => $candidateSha256,
            'testSha256' => $testSha256,
            'isSimulated' => $isSimulated,
            'issuedAt' => $now,
            'expiresAt' => $expiresAt,
            'hmac' => $hmac,
        ];
        if ($privateProvenanceId !== null) $record['privateProvenanceId'] = $privateProvenanceId;

        $tokenPath = $this->tokenPath($token);
        $data = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";

        $handle = fopen($tokenPath, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Failed to allocate unique publication authorization file.');
        }

        try {
            chmod($tokenPath, 0600);
            if (fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
                throw new \RuntimeException('Failed to persist publication authorization record.');
            }
        } finally {
            fclose($handle);
        }

        return $token;
    }

    /**
     * Atomically verifies and consumes a server-held publication authorization token.
     * Uses atomic POSIX rename to eliminate race conditions across concurrent processes.
     */
    public function verifyAndConsume(
        string $token,
        string $runId,
        string $evidenceDirectory,
        string $receiptSha256,
        string $candidateSha256,
        ?string $privateProvenanceId = null
    ): array {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new \InvalidArgumentException('Invalid publication token format.');
        }

        $tokenPath = $this->tokenPath($token);
        $consumedPath = $tokenPath . '.' . bin2hex(random_bytes(8)) . '.consumed';

        // Atomic transition: exactly one concurrent consumer can succeed this rename
        if (!@rename($tokenPath, $consumedPath)) {
            throw new \RuntimeException(
                'Publication authorization token is invalid, expired, or was already consumed. Unanchored local receipts cannot authorize publishing.'
            );
        }

        $handle = fopen($consumedPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open consumed publication authorization record.');
        }

        try {
            flock($handle, LOCK_EX);
            $content = (string) stream_get_contents($handle);
            $record = json_decode($content, true);

            if (!is_array($record)) {
                throw new \RuntimeException('Publication authorization record is corrupted.');
            }

            // Verify TTL
            if (time() > (int) ($record['expiresAt'] ?? 0)) {
                throw new \RuntimeException('Publication authorization token has expired.');
            }

            // Verify server HMAC signature
            $expectedPayload = $this->buildPayloadString(
                $token,
                (string) ($record['runId'] ?? ''),
                (string) ($record['evidenceDirectory'] ?? ''),
                (string) ($record['receiptSha256'] ?? ''),
                (string) ($record['baseCommit'] ?? ''),
                (string) ($record['repository'] ?? ''),
                (int) ($record['issueNumber'] ?? 0),
                (string) ($record['filePath'] ?? ''),
                (string) ($record['baseSha256'] ?? ''),
                (string) ($record['candidateSha256'] ?? ''),
                (string) ($record['testSha256'] ?? ''),
                (bool) ($record['isSimulated'] ?? false),
                $record['privateProvenanceId'] ?? null
            );

            $expectedHmac = hash_hmac('sha256', $expectedPayload, $this->serverSecret);
            if (!hash_equals($expectedHmac, (string) ($record['hmac'] ?? ''))) {
                throw new \RuntimeException('Publication authorization record failed HMAC signature verification.');
            }
            if (($record['privateProvenanceId'] ?? null) !== $privateProvenanceId) {
                throw new \RuntimeException('Publication authorization does not match the private proposal run.');
            }

            // Strictly reject simulated/mocked runs from publication
            if (!empty($record['isSimulated'])) {
                throw new \RuntimeException(
                    'Cannot publish proposal: Verification was executed using a mock/simulated runner, not genuine provider microVM isolation.'
                );
            }

            // Verify exact run, evidence directory, receipt digest, and candidate digest
            if ($record['runId'] !== $runId) {
                throw new \RuntimeException("Authorization token run ID mismatch: expected '{$record['runId']}', got '{$runId}'.");
            }

            $resolvedEvidence = realpath($evidenceDirectory) ?: $evidenceDirectory;
            if ($record['evidenceDirectory'] !== $resolvedEvidence) {
                throw new \RuntimeException('Authorization token evidence directory mismatch.');
            }

            if (!hash_equals($record['receiptSha256'], $receiptSha256)) {
                throw new \RuntimeException('Authorization token receipt digest mismatch.');
            }

            if (!hash_equals($record['candidateSha256'], $candidateSha256)) {
                throw new \RuntimeException('Authorization token candidate hash mismatch.');
            }

            return $record;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($consumedPath);
        }
    }

    /**
     * Looks up an existing unconsumed, non-expired, valid server-held token for a given run and candidate.
     * Returns null if no valid token exists, if it has expired, or if it was already consumed.
     * Never stores tokens in database; checks the server-held private storage directory.
     */
    public function findActiveTokenForRun(string $runId, string $candidateSha256): ?string
    {
        if (!is_dir($this->storageDir)) {
            return null;
        }

        $files = glob($this->storageDir . '/*.json') ?: [];
        $now = time();

        foreach ($files as $file) {
            if (str_ends_with($file, '.consumed')) {
                continue;
            }

            $content = @file_get_contents($file);
            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            try {
                $record = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($record)) {
                continue;
            }

            if (($record['runId'] ?? '') !== $runId) {
                continue;
            }

            if (!hash_equals((string) ($record['candidateSha256'] ?? ''), $candidateSha256)) {
                continue;
            }

            if ($now > (int) ($record['expiresAt'] ?? 0)) {
                continue;
            }

            $token = (string) ($record['token'] ?? '');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
                continue;
            }

            $expectedPayload = $this->buildPayloadString(
                $token,
                (string) ($record['runId'] ?? ''),
                (string) ($record['evidenceDirectory'] ?? ''),
                (string) ($record['receiptSha256'] ?? ''),
                (string) ($record['baseCommit'] ?? ''),
                (string) ($record['repository'] ?? ''),
                (int) ($record['issueNumber'] ?? 0),
                (string) ($record['filePath'] ?? ''),
                (string) ($record['baseSha256'] ?? ''),
                (string) ($record['candidateSha256'] ?? ''),
                (string) ($record['testSha256'] ?? ''),
                (bool) ($record['isSimulated'] ?? false),
                $record['privateProvenanceId'] ?? null
            );

            if (!hash_equals(hash_hmac('sha256', $expectedPayload, $this->serverSecret), (string) ($record['hmac'] ?? ''))) {
                continue;
            }

            return $token;
        }

        return null;
    }

    public function revoke(string $token): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $path = $this->tokenPath($token);
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function revokeForRun(string $runId, ?string $candidateSha256 = null): void
    {
        if (!is_dir($this->storageDir)) {
            return;
        }

        $files = glob($this->storageDir . '/*.json') ?: [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.consumed')) {
                continue;
            }

            $content = @file_get_contents($file);
            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            try {
                $record = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($record)) {
                continue;
            }

            if (($record['runId'] ?? '') !== $runId) {
                continue;
            }

            if ($candidateSha256 !== null && $candidateSha256 !== '') {
                if (!hash_equals((string) ($record['candidateSha256'] ?? ''), $candidateSha256)) {
                    continue;
                }
            }

            @unlink($file);
        }
    }

    private function tokenPath(string $token): string
    {
        return $this->storageDir . '/' . $token . '.json';
    }

    private function buildPayloadString(
        string $token,
        string $runId,
        string $evidenceDir,
        string $receiptSha,
        string $baseCommit,
        string $repository,
        int $issueNumber,
        string $filePath,
        string $baseSha,
        string $candidateSha,
        string $testSha,
        bool $isSimulated,
        ?string $privateProvenanceId = null
    ): string {
        $payload = implode(':', [
            $token,
            $runId,
            $evidenceDir,
            $receiptSha,
            $baseCommit,
            $repository,
            (string) $issueNumber,
            $filePath,
            $baseSha,
            $candidateSha,
            $testSha,
            $isSimulated ? '1' : '0',
        ]);
        return $privateProvenanceId === null ? $payload : $payload.':private-proposal-v1:'.$privateProvenanceId;
    }

    private function resolveServerSecret(): string
    {
        $appKey = getenv('APP_KEY');
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
