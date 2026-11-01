<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use ProofPatch\Evidence\CanonicalJson;

/** Stores the observed GitHub metadata as a local, independently hashable snapshot. */
final readonly class PullRequestSnapshot
{
    public function __construct(private string $directory)
    {
    }

    public function capture(array $pull): array
    {
        if (!is_string($pull['headSha'] ?? null) || !preg_match('/\A[a-f0-9]{40}\z/', $pull['headSha'])
            || !is_string($pull['repository'] ?? null) || !is_int($pull['number'] ?? null)) {
            throw new \InvalidArgumentException('Pull request data is incomplete.');
        }
        if (is_link($this->directory) || (!is_dir($this->directory) && !mkdir($this->directory, 0700, true))) {
            throw new \RuntimeException('Cannot create the snapshot directory.');
        }
        chmod($this->directory, 0700);
        $id = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
        $payload = ['format' => 'proofpatch-github-snapshot-v1', 'id' => $id, 'capturedAt' => gmdate(DATE_ATOM), 'pullRequest' => $pull];
        $digest = hash('sha256', CanonicalJson::encode($payload));
        $file = $this->directory.'/'.$id.'.json';
        $handle = fopen($file, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create the snapshot.');
        }
        $written = false;
        try {
            chmod($file, 0600);
            $data = json_encode(['snapshot' => $payload, 'sha256' => $digest], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
            if (fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write the snapshot.');
            }
            $written = true;
        } finally {
            fclose($handle);
            if (!$written && file_exists($file)) {
                @unlink($file);
            }
        }
        return ['id' => $id, 'sha256' => $digest, 'capturedAt' => $payload['capturedAt']];
    }

    public function verify(string $id, ?string $expectedDigest = null): array
    {
        $document = $this->verifiedDocument($id, $expectedDigest);
        return ['integrity' => 'VALID', 'sha256' => $document['sha256'], 'externallyAnchored' => $expectedDigest !== null];
    }

    public function load(string $id): array
    {
        $document = $this->verifiedDocument($id, null);
        return ['snapshot' => $document['snapshot'], 'sha256' => $document['sha256']];
    }

    public static function isExplicitlyPublic(array $savedSnapshot): bool
    {
        return \ProofPatch\Application\Repository\SnapshotVisibilityPolicy::isExplicitlyPublic($savedSnapshot);
    }

    private function verifiedDocument(string $id, ?string $expectedDigest): array
    {
        if (!preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $id) || is_link($this->directory)) {
            throw new \InvalidArgumentException('Invalid snapshot ID.');
        }
        $file = $this->directory.'/'.$id.'.json';
        if (is_link($file) || !is_file($file) || filesize($file) > 2097152) {
            throw new \InvalidArgumentException('Snapshot is missing or invalid.');
        }
        try {
            $document = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Snapshot is missing or invalid.', 0, $error);
        }
        if (!is_array($document) || !is_array($document['snapshot'] ?? null) || !is_string($document['sha256'] ?? null)
            || ($document['snapshot']['id'] ?? null) !== $id || ($document['snapshot']['format'] ?? null) !== 'proofpatch-github-snapshot-v1') {
            throw new \InvalidArgumentException('Snapshot has an invalid format.');
        }
        $digest = hash('sha256', CanonicalJson::encode($document['snapshot']));
        if (!hash_equals($document['sha256'], $digest) || ($expectedDigest !== null && !hash_equals($expectedDigest, $digest))) {
            throw new \InvalidArgumentException('Snapshot digest does not match.');
        }
        return $document;
    }
}
