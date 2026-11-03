<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

/** Fetch a bounded set of distinct changed files without following arbitrary links. */
final class PullRequestChangedFiles
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 3;

    /** @param callable(string): array $get @return list<array<string, mixed>> */
    public static function fetch(callable $get, string $pullUrl, int $expectedCount): array
    {
        $files = [];
        $names = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $url = $pullUrl.'/files?per_page='.self::PAGE_SIZE.($page > 1 ? '&page='.$page : '');
            $items = $get($url);
            if (!array_is_list($items) || count($items) > self::PAGE_SIZE) {
                throw new \RuntimeException('GitHub returned invalid changed files.');
            }
            foreach ($items as $file) {
                if (!is_array($file) || !is_string($file['filename'] ?? null) || $file['filename'] === ''
                    || strlen($file['filename']) > 4096 || isset($names[$file['filename']])) {
                    throw new \RuntimeException('GitHub returned invalid or duplicate changed files.');
                }
                $names[$file['filename']] = true;
                $files[] = $file;
                if (count($files) > $expectedCount) {
                    throw new \RuntimeException('GitHub returned more files than the pull request reports.');
                }
            }
            // A short page is incomplete if the reported count is higher; the
            // caller records that fact and the review guard refuses inference.
            if (count($items) < self::PAGE_SIZE || count($files) >= $expectedCount) break;
        }
        return $files;
    }
}
