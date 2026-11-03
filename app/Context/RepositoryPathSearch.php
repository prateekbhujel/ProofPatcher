<?php

declare(strict_types=1);

namespace ProofPatch\Context;

use Symfony\Component\Process\Process;

/** Read-only path search over explicitly supplied local Git commit trees. */
final class RepositoryPathSearch
{
    private const MAX_REPOSITORIES = 5;
    private const MAX_TREE_BYTES = 8388608;
    private const MAX_RESULTS = 25;

    /**
     * Paths are local metadata only: no source contents are read and nothing
     * is transmitted to a model. HEAD is resolved independently per repository.
     *
     * @param list<string> $repositories
     * @return list<array{repository:string,commit:string,scanned:int,matches:int,paths:list<string>,omittedUnsafe:int}>
     */
    public function search(array $repositories, string $query, string $revision = 'HEAD'): array
    {
        $query = trim($query);
        if ($repositories === [] || count($repositories) > self::MAX_REPOSITORIES) {
            throw new \InvalidArgumentException('Provide between one and five repository paths.');
        }
        if ($query === '' || strlen($query) > 120 || !mb_check_encoding($query, 'UTF-8') || preg_match('/[\p{C}]/u', $query)) {
            throw new \InvalidArgumentException('Provide a printable path search of at most 120 bytes.');
        }
        if ($revision !== 'HEAD' && !preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $revision)) {
            throw new \InvalidArgumentException('Revision must be HEAD or a full lowercase commit hash.');
        }

        $results = [];
        foreach ($repositories as $path) {
            $dir = is_string($path) ? realpath($path) : false;
            if ($dir === false || !is_dir($dir) || !mb_check_encoding($dir, 'UTF-8') || preg_match('/[\p{C}]/u', $dir)) {
                throw new \InvalidArgumentException('A repository path is not an existing directory.');
            }
            // Git accepts a path inside a worktree. Normalize it to the root
            // so ls-tree and grep search the same repository and use the same
            // root-relative paths. Bare repositories have no worktree root.
            $selected = $dir;
            $gitDir = realpath(rtrim($this->git($selected, ['rev-parse', '--absolute-git-dir'], 16384), "\r\n"));
            if ($gitDir === false) {
                throw new \RuntimeException('Could not identify the local Git repository.');
            }
            $bare = trim($this->git($dir, ['rev-parse', '--is-bare-repository'], 32));
            if (!in_array($bare, ['true', 'false'], true)) {
                throw new \RuntimeException('Could not identify the local Git repository.');
            }
            $root = rtrim($this->git($dir, ['rev-parse', $bare === 'true' ? '--absolute-git-dir' : '--show-toplevel'], 16384), "\r\n");
            $dir = realpath($root);
            if ($dir === false || !is_dir($dir) || !mb_check_encoding($dir, 'UTF-8') || preg_match('/[\p{C}]/u', $dir)) {
                throw new \RuntimeException('Could not identify the local Git repository root.');
            }
            // A repository can point core.worktree at another repository.
            // Do not silently switch the user's selection to that repository.
            if ($selected !== $dir && !str_starts_with($selected, rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('The selected path is outside its Git repository root.');
            }
            $rootGitDir = realpath(rtrim($this->git($dir, ['rev-parse', '--absolute-git-dir'], 16384), "\r\n"));
            if ($rootGitDir === false || $rootGitDir !== $gitDir) {
                throw new \RuntimeException('The selected path resolves to a different Git repository.');
            }
            $commit = trim($this->git($dir, ['rev-parse', '--verify', '--end-of-options', $revision.'^{commit}'], 256));
            if (!preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $commit)) {
                throw new \RuntimeException('Could not resolve an exact Git commit.');
            }
            $tree = $this->git($dir, ['ls-tree', '-r', '-z', '--full-tree', $commit], self::MAX_TREE_BYTES);
            $paths = [];
            $scanned = $matches = $omitted = 0;
            foreach (explode("\0", $tree) as $entry) {
                if ($entry === '') continue;
                if (!preg_match('/\A[0-7]{6} blob [a-f0-9]{40,64}\t(.*)\z/s', $entry, $match)) continue;
                $pathName = $match[1];
                ++$scanned;
                if (strlen($pathName) > 4096 || !mb_check_encoding($pathName, 'UTF-8') || preg_match('/[\p{C}]/u', $pathName)) {
                    ++$omitted;
                    continue;
                }
                if (mb_stripos($pathName, $query, 0, 'UTF-8') === false) continue;
                ++$matches;
                if (count($paths) < self::MAX_RESULTS) $paths[] = $pathName;
            }
            $results[] = ['repository' => $dir, 'commit' => $commit, 'scanned' => $scanned,
                'matches' => $matches, 'paths' => $paths, 'omittedUnsafe' => $omitted];
        }
        return $results;
    }

    /**
     * Find one literal source line per matching file, on the same pinned
     * commit trees. Output is local and can contain sensitive source text.
     * Nothing is sent to a model and binary files are skipped by Git.
     *
     * @param list<string> $repositories
     * @return list<array{repository:string,commit:string,matches:int,results:list<array{path:string,line:int,text:string,textTruncated:bool}>,omittedUnsafe:int}>
     */
    public function searchContent(array $repositories, string $query, string $revision = 'HEAD'): array
    {
        $trees = $this->search($repositories, $query, $revision);
        $results = [];
        foreach ($trees as $tree) {
            $raw = $this->git($tree['repository'], ['grep', '-F', '-I', '-n', '-z', '-m', '1',
                '--no-color', '--no-column', '--full-name', '--no-ext-grep', '--no-textconv', '-e', trim($query), $tree['commit'], '--'],
                self::MAX_TREE_BYTES, true);
            $position = 0;
            $entries = [];
            $matches = $omitted = 0;
            while ($position < strlen($raw)) {
                $pathEnd = strpos($raw, "\0", $position);
                $lineEnd = $pathEnd === false ? false : strpos($raw, "\0", $pathEnd + 1);
                $textEnd = $lineEnd === false ? false : strpos($raw, "\n", $lineEnd + 1);
                if ($pathEnd === false || $lineEnd === false || $textEnd === false) {
                    throw new \RuntimeException('Git returned an incomplete content search result.');
                }
                $source = substr($raw, $position, $pathEnd - $position);
                $line = substr($raw, $pathEnd + 1, $lineEnd - $pathEnd - 1);
                $text = substr($raw, $lineEnd + 1, $textEnd - $lineEnd - 1);
                $position = $textEnd + 1;
                $prefix = $tree['commit'].':';
                if (!str_starts_with($source, $prefix) || !preg_match('/\A[1-9][0-9]{0,8}\z/', $line)) {
                    throw new \RuntimeException('Git returned an invalid content search result.');
                }
                $path = substr($source, strlen($prefix));
                if (strlen($path) > 4096 || !mb_check_encoding($path, 'UTF-8') || preg_match('/[\p{C}]/u', $path)) {
                    ++$omitted;
                    continue;
                }
                ++$matches;
                if (count($entries) >= self::MAX_RESULTS) continue;
                $safeText = (string) preg_replace('/[\p{C}]/u', ' ', mb_scrub($text, 'UTF-8'));
                $entries[] = ['path' => $path, 'line' => (int) $line,
                    'text' => mb_strimwidth($safeText, 0, 300, '…', 'UTF-8'),
                    'textTruncated' => mb_strwidth($safeText, 'UTF-8') > 300];
            }
            $results[] = ['repository' => $tree['repository'], 'commit' => $tree['commit'],
                'matches' => $matches, 'results' => $entries, 'omittedUnsafe' => $omitted];
        }
        return $results;
    }

    /** @param list<string> $args */
    private function git(string $dir, array $args, int $maxBytes, bool $allowNoMatch = false): string
    {
        // Disable inherited Git redirection, global config, lazy fetch and hooks.
        // No shell, checkout, external diff, or repository code runs.
        $env = [
            'GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false,
            'GIT_OBJECT_DIRECTORY' => false, 'GIT_ALTERNATE_OBJECT_DIRECTORIES' => false,
            'GIT_CONFIG_PARAMETERS' => false, 'GIT_CONFIG_COUNT' => false,
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_NO_LAZY_FETCH' => '1',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_CURL_VERBOSE' => false,
        ];
        // Inherited tracing would otherwise write private search terms and
        // paths to a user-configured log, including trace2 variants.
        foreach (array_keys(array_merge(getenv() ?: [], $_SERVER, $_ENV)) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_TRACE')) $env[$name] = false;
        }
        $process = new Process(['git', '-c', 'core.fsmonitor=false', '-C', $dir, ...$args], null, $env, null, 15);
        $output = '';
        $errorBytes = 0;
        try {
            $process->run(static function (string $type, string $chunk) use (&$output, &$errorBytes, $maxBytes): void {
                if ($type !== Process::OUT) {
                    $errorBytes += strlen($chunk);
                    if ($errorBytes > 65536) throw new \RuntimeException('Git error output exceeded its limit.');
                    return;
                }
                if (strlen($output) + strlen($chunk) > $maxBytes) {
                    throw new \RuntimeException('Git output exceeds the search limit; no partial results were returned.');
                }
                $output .= $chunk;
            });
        } catch (\Throwable $error) {
            $process->stop(0);
            throw new \RuntimeException('Repository search failed: '.$error->getMessage(), 0, $error);
        }
        if (!$process->isSuccessful() && !($allowNoMatch && $process->getExitCode() === 1)) {
            throw new \RuntimeException('Could not read the requested local Git commit.');
        }
        return $output;
    }
}
