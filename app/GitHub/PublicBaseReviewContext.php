<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Fetch bounded old-side context for one changed public file. */
final readonly class PublicBaseReviewContext
{
    private const MAX_BLOB_BYTES = 524288;
    private const MAX_RESPONSE_BYTES = 2097152;
    private const MAX_CONTEXT_BYTES = 12000;

    public function __construct(private HttpClientInterface $http, private ?string $token = null)
    {
        if ($token !== null && (strlen($token) < 16 || strlen($token) > 4096 || !preg_match('/\A[\x21-\x7e]+\z/', $token))) {
            throw new \InvalidArgumentException('GitHub credentials are invalid.');
        }
    }

    /** The input preview must be freshly fetched from PublicPatchPreview. */
    public function fetch(array $pull, array $preview, string $path): array
    {
        $repo = $pull['repository'] ?? null;
        $number = $pull['number'] ?? null;
        $head = $pull['headSha'] ?? null;
        $base = $pull['baseSha'] ?? null;
        if (!is_string($repo) || !preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repo, $parts)
            || in_array($parts[2], ['.', '..'], true) || !is_int($number) || $number < 1
            || !is_string($head) || !preg_match('/\A[a-f0-9]{40}\z/', $head)
            || !is_string($base) || !preg_match('/\A[a-f0-9]{40}\z/', $base)
            || ($pull['isPrivate'] ?? true) !== false || ($preview['isPrivate'] ?? true) !== false
            || ($preview['repository'] ?? null) !== $repo || ($preview['number'] ?? null) !== $number
            || ($preview['headSha'] ?? null) !== $head || ($preview['baseSha'] ?? null) !== $base
            || ($preview['filesTruncated'] ?? true) !== false) {
            throw new \InvalidArgumentException('A current public pull request preview is required.');
        }
        if ($path === '' || strlen($path) > 1024 || !mb_check_encoding($path, 'UTF-8')
            || preg_match('/[\p{C}\\\\]/u', $path) || str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Choose a valid changed file.');
        }
        $segments = explode('/', $path);
        if (count($segments) > 12 || array_any($segments, static fn(string $s): bool => $s === '' || $s === '.' || $s === '..')) {
            throw new \InvalidArgumentException('Choose a valid changed file.');
        }
        $patch = null;
        foreach ($preview['files'] ?? [] as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $path && ($file['status'] ?? null) === 'available' && is_string($file['patch'] ?? null)) {
                $patch = $file['patch'];
                break;
            }
        }
        if ($patch === null) throw new \InvalidArgumentException('Choose a file with a complete changed-file diff.');
        $root = 'https://api.github.com/repos/'.rawurlencode($parts[1]).'/'.rawurlencode($parts[2]);
        $commit = $this->get($root.'/git/commits/'.$base);
        $treeSha = $commit['tree']['sha'] ?? null;
        if (($commit['sha'] ?? null) !== $base || !self::sha($treeSha)) throw new \RuntimeException('Base commit is unavailable.');
        $blobSha = null;
        foreach ($segments as $index => $segment) {
            $tree = $this->get($root.'/git/trees/'.$treeSha);
            if (($tree['sha'] ?? null) !== $treeSha || ($tree['truncated'] ?? null) !== false || !is_array($tree['tree'] ?? null)) {
                throw new \RuntimeException('Base file tree is unavailable or incomplete.');
            }
            $entry = null;
            foreach ($tree['tree'] as $candidate) {
                if (is_array($candidate) && ($candidate['path'] ?? null) === $segment) {
                    if ($entry !== null) throw new \RuntimeException('Base file tree is ambiguous.');
                    $entry = $candidate;
                }
            }
            if (!is_array($entry) || !self::sha($entry['sha'] ?? null)) throw new \RuntimeException('This file is unavailable at the base revision.');
            if ($index < count($segments) - 1) {
                if (($entry['type'] ?? null) !== 'tree' || ($entry['mode'] ?? null) !== '040000') throw new \RuntimeException('Base path is not a regular file.');
                $treeSha = $entry['sha'];
            } else {
                if (($entry['type'] ?? null) !== 'blob' || !in_array($entry['mode'] ?? null, ['100644', '100755'], true)) {
                    throw new \RuntimeException('Base context is available only for regular files.');
                }
                $blobSha = $entry['sha'];
            }
        }
        $blob = $this->get($root.'/git/blobs/'.$blobSha);
        $size = $blob['size'] ?? null;
        if (($blob['sha'] ?? null) !== $blobSha || ($blob['encoding'] ?? null) !== 'base64' || !is_int($size)
            || $size < 0 || $size > self::MAX_BLOB_BYTES || !is_string($blob['content'] ?? null)) {
            throw new \RuntimeException('Base file is unavailable or too large.');
        }
        $encoded = str_replace(["\r", "\n"], '', $blob['content']);
        $source = base64_decode($encoded, true);
        if (!is_string($source) || strlen($source) !== $size || !hash_equals($blobSha, sha1('blob '.$size."\0".$source))
            || str_contains($source, "\0") || !mb_check_encoding($source, 'UTF-8')
            || ($this->token !== null && str_contains($source, $this->token))) {
            throw new \RuntimeException('Base file content could not be verified as text.');
        }
        $lines = $source === '' ? [] : explode("\n", $source);
        if (str_ends_with($source, "\n")) array_pop($lines);
        $starts = self::verifiedHunkStarts($patch, $lines, str_ends_with($source, "\n"));
        $snippets = [];
        $total = 0;
        foreach ($starts as $start) {
            if ($start > count($lines)) throw new \RuntimeException('Diff base lines do not match the captured file.');
            $first = max(1, $start - 8);
            $last = min(count($lines), $start + 8);
            $selected = [];
            foreach (range($first, $last) as $line) {
                $text = $lines[$line - 1];
                if (strlen($text) > 1000) $text = mb_strcut($text, 0, 1000, 'UTF-8').' [line clipped]';
                $total += strlen($text);
                if ($total > self::MAX_CONTEXT_BYTES) throw new \RuntimeException('Base context exceeds its safe limit.');
                $selected[] = $text;
            }
            $snippets[] = ['startLine' => $first, 'endLine' => $last, 'text' => implode("\n", $selected)];
        }
        return ['repository' => $repo, 'number' => $number, 'headSha' => $head, 'baseSha' => $base,
            'path' => $path, 'blobSha' => $blobSha, 'snippets' => $snippets];
    }

    private static function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{40}\z/', $value) === 1;
    }

    /**
     * GitHub patches may use the merge base rather than the current base tip.
     * Compare every old/context line before associating hunk coordinates with
     * the base-tip blob. If it has shifted, do not send misleading snippets.
     *
     * @param list<string> $sourceLines
     * @return list<int>
     */
    private static function verifiedHunkStarts(string $patch, array $sourceLines, bool $endsWithNewline): array
    {
        $rawLines = explode("\n", $patch);
        if (end($rawLines) === '') array_pop($rawLines);
        $hunk = null;
        $starts = [];
        $previousEnd = 0;
        $previousType = null;
        $markerRequired = false;
        $finalize = static function (?array $hunk) use (&$starts, &$previousEnd, &$markerRequired): void {
            if ($hunk === null || $markerRequired || $hunk['oldSeen'] !== $hunk['oldCount'] || $hunk['newSeen'] !== $hunk['newCount']) {
                throw new \RuntimeException('The diff hunk is incomplete or does not match the base file.');
            }
            if ($hunk['oldCount'] > 0) {
                if ($hunk['oldStart'] <= $previousEnd) throw new \RuntimeException('The diff hunks overlap or are out of order.');
                $previousEnd = $hunk['oldStart'] + $hunk['oldCount'] - 1;
                if (count($starts) < 3) $starts[] = $hunk['oldStart'];
            }
        };
        foreach ($rawLines as $line) {
            if (preg_match('/\A@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(?:.*)\z/', $line, $match)) {
                if ($hunk !== null) $finalize($hunk);
                $oldStart = (int) $match[1];
                $oldCount = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 1;
                $newStart = (int) $match[3];
                $newCount = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 1;
                if (($oldCount > 0 && $oldStart < 1) || ($newCount > 0 && $newStart < 1)) {
                    throw new \RuntimeException('The diff hunk has invalid line numbers.');
                }
                $hunk = ['oldStart' => $oldStart, 'oldCount' => $oldCount, 'newCount' => $newCount, 'oldSeen' => 0, 'newSeen' => 0];
                $previousType = null;
                continue;
            }
            if ($hunk === null) throw new \RuntimeException('The diff has no valid hunk header.');
            if ($line === '\\ No newline at end of file') {
                if ($previousType === null || $previousType === 'marker') throw new \RuntimeException('The diff has an invalid newline marker.');
                if ($previousType !== '+' && !$markerRequired) throw new \RuntimeException('The diff newline marker does not match the base file.');
                $markerRequired = false;
                $previousType = 'marker';
                continue;
            }
            if ($markerRequired) throw new \RuntimeException('The diff newline marker is missing.');
            $type = $line[0] ?? '';
            if (!in_array($type, [' ', '-', '+'], true)) throw new \RuntimeException('The diff hunk is malformed.');
            if ($type !== '+') {
                $lineNumber = $hunk['oldStart'] + $hunk['oldSeen'];
                if ($lineNumber < 1 || !array_key_exists($lineNumber - 1, $sourceLines)
                    || $sourceLines[$lineNumber - 1] !== substr($line, 1)) {
                    throw new \RuntimeException('Diff lines do not match the current base file; no base context was included.');
                }
                ++$hunk['oldSeen'];
                $markerRequired = !$endsWithNewline && $lineNumber === count($sourceLines);
            }
            if ($type !== '-') ++$hunk['newSeen'];
            if ($hunk['oldSeen'] > $hunk['oldCount'] || $hunk['newSeen'] > $hunk['newCount']) {
                throw new \RuntimeException('The diff hunk exceeds its declared line counts.');
            }
            $previousType = $type;
        }
        $finalize($hunk);
        if ($starts === []) throw new \RuntimeException('This file has no verifiable base lines to include.');
        return $starts;
    }

    private function get(string $url): array
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'ProofPatcher', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($this->token !== null) $headers['Authorization'] = 'Bearer '.$this->token;
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => $headers, 'max_redirects' => 0, 'timeout' => 15, 'max_duration' => 20,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (\Throwable) {
            throw new \RuntimeException('Public base context request failed.');
        }
        if ($status !== 200) throw new \RuntimeException('Public base context is unavailable (HTTP '.$status.').');
        if (strlen($raw) > self::MAX_RESPONSE_BYTES || ($this->token !== null && str_contains($raw, $this->token))) throw new \RuntimeException('Public base context response is invalid.');
        try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('Public base context response is invalid.'); }
        if (!is_array($data) || ResponseTokenGuard::contains($data, $this->token)) throw new \RuntimeException('Public base context response is invalid.');
        return $data;
    }
}
