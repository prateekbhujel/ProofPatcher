<?php

declare(strict_types=1);

namespace ProofPatch\Agent;

use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Owner-scoped provenance for private model suggestions.
 *
 * Callers must provide a freshly verified GitHub grant and may record only
 * the already validated result of an actual provider request. A record is
 * provenance, never verification or permission to publish.
 *
 * Binding covers the verified GitHub identity, repository, issue, base
 * commit, path, source digest, and candidate digest. Lookup returns nothing
 * without the exact binding and a current grant, so revoked access or
 * changed content fails closed. Identifiers never appear in filenames or
 * ciphertext; an identical rewrite is idempotent, a conflicting one throws.
 */
final readonly class PrivateProposalStore
{
    public function __construct(private string $directory, private Encrypter $encrypter, private string $namespaceKey)
    {
        if (strlen($namespaceKey) < 32) {
            throw new \InvalidArgumentException('A distinct secret namespace key is required.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create private proposal storage.');
        }
        if (is_link($directory) || !chmod($directory, 0700)) {
            throw new \RuntimeException('Cannot protect private proposal storage.');
        }
    }

    public static function forRuntime(?string $directory = null): self
    {
        $override = getenv('PROOFPATCH_PRIVATE_PROPOSALS_DIR');
        $dir = $directory ?? (is_string($override) && trim($override) !== ''
            ? trim($override)
            : (function_exists('storage_path') ? storage_path('app/private_proposals') : sys_get_temp_dir() . '/proofpatch_private_proposals'));
        $key = function_exists('config') ? (string) config('app.key') : '';
        if ($key === '') {
            $key = (string) (getenv('APP_KEY') ?: '');
        }
        if (strlen($key) < 16) {
            throw new \RuntimeException('Application key is required for private proposal storage.');
        }
        return new self($dir, app('encrypter'), hash_hmac('sha256', 'proofpatch-private-proposal-path-v1', $key, true));
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $grant */
    public function record(array $binding, array $grant, array $proposal, ?string $runId = null): void
    {
        $binding = $this->normalize($binding);
        $this->validateRunId($runId);
        if (!$this->authorized($binding, $grant)) {
            throw new \RuntimeException('Current GitHub access is required.');
        }
        $source = $proposal['candidateSource'] ?? null;
        $model = $proposal['model'] ?? null;
        $requestDigest = $proposal['requestDigest'] ?? null;
        $usage = $proposal['usage'] ?? null;
        if (!is_string($source) || strlen($source) > 65536 || !hash_equals($binding['candidateSha256'], hash('sha256', $source))
            || !is_string($model) || $model === '' || strlen($model) > 128
            || !is_string($requestDigest) || !preg_match('/\A[a-f0-9]{64}\z/', $requestDigest)
            || !is_array($usage) || !is_int($usage['prompt_tokens'] ?? null) || $usage['prompt_tokens'] < 0
            || !is_int($usage['completion_tokens'] ?? null) || $usage['completion_tokens'] < 0) {
            throw new \InvalidArgumentException('Invalid candidate or provider provenance.');
        }
        $record = ['binding' => $binding, 'candidateSource' => $source, 'model' => $model,
            'requestDigest' => $requestDigest, 'usage' => ['prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens']]];
        if ($runId !== null) $record['privateProvenanceId'] = $runId;
        $target = $this->path($binding, $runId);
        $this->guardOverwrite($target, $record, $grant, $runId);
        $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encrypted = $this->encrypter->encryptString($encoded);
        $temporary = $this->directory . '/.' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create private proposal.');
        }
        try {
            if (!chmod($temporary, 0600) || fwrite($handle, $encrypted) !== strlen($encrypted) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write private proposal.');
            }
            fclose($handle);
            $handle = null;
            // Link is atomic and never overwrites a competing record. If an
            // identical record won the race, the guarded lookup accepts it.
            if (!@link($temporary, $target)) {
                if (!is_file($target) || is_link($target)) throw new \RuntimeException('Cannot save private proposal.');
                $this->guardOverwrite($target, $record, $grant, $runId);
            }
            chmod($target, 0600);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Returns no data without the exact binding and a current verified grant. */
    public function lookup(array $binding, array $grant, ?string $runId = null): ?array
    {
        try {
            $binding = $this->normalize($binding);
            $this->validateRunId($runId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (!$this->authorized($binding, $grant)) {
            return null;
        }
        $path = $this->path($binding, $runId);
        if (!is_file($path) || is_link($path) || filesize($path) > 131072) {
            return null;
        }
        try {
            $ciphertext = file_get_contents($path);
            if (!is_string($ciphertext)) {
                return null;
            }
            $record = json_decode($this->encrypter->decryptString($ciphertext), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['binding'] ?? null) !== $binding
                || ($record['privateProvenanceId'] ?? null) !== $runId
                || !is_string($record['candidateSource'] ?? null)
                || !hash_equals($binding['candidateSha256'], hash('sha256', $record['candidateSource']))) {
                return null;
            }
            return $record;
        } catch (\Throwable) {
            return null;
        }
    }

    /** An identical rewrite is idempotent; anything else on record throws. */
    private function guardOverwrite(string $target, array $record, array $grant, ?string $runId): void
    {
        if (!is_file($target) || is_link($target)) {
            return;
        }
        $existing = $this->lookup($record['binding'], $grant, $runId);
        if ($existing === null || $existing !== $record) {
            throw new \RuntimeException('A different private proposal is already recorded for this change.');
        }
    }

    private function normalize(array $binding): array
    {
        $id = $binding['githubUserId'] ?? null;
        $repo = $binding['repository'] ?? null;
        $issue = $binding['issueNumber'] ?? null;
        $commit = $binding['baseCommit'] ?? null;
        $path = $binding['path'] ?? null;
        $source = $binding['sourceSha256'] ?? null;
        $candidate = $binding['candidateSha256'] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($repo) || !preg_match('~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}\z~', $repo)
            || !is_int($issue) || $issue < 1 || !is_string($commit) || !preg_match('/\A[a-f0-9]{40}\z/', $commit)
            || !is_string($path) || $path === '' || strlen($path) > 512 || str_contains($path, "\0")
            || !is_string($source) || !preg_match('/\A[a-f0-9]{64}\z/', $source)
            || !is_string($candidate) || !preg_match('/\A[a-f0-9]{64}\z/', $candidate)) {
            throw new \InvalidArgumentException('Invalid private proposal binding.');
        }
        return ['githubUserId' => $id, 'repository' => strtolower($repo), 'issueNumber' => $issue,
            'baseCommit' => $commit, 'path' => $path, 'sourceSha256' => $source, 'candidateSha256' => $candidate];
    }

    private function authorized(array $binding, array $grant): bool
    {
        return ($grant['userId'] ?? null) === $binding['githubUserId']
            && (($grant['repositories'][$binding['repository']] ?? null) === true);
    }

    private function validateRunId(?string $runId): void
    {
        if ($runId !== null && !preg_match('/\A[a-f0-9]{32}\z/', $runId)) {
            throw new \InvalidArgumentException('Invalid private proposal run identifier.');
        }
    }

    private function path(array $binding, ?string $runId = null): string
    {
        $input = json_encode($binding, JSON_THROW_ON_ERROR);
        if ($runId !== null) $input .= ':run:'.$runId;
        return $this->directory . '/' . hash_hmac('sha256', $input, $this->namespaceKey) . '.enc';
    }
}
