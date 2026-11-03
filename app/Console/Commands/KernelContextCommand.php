<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use ProofPatch\Context\RepositoryPathSearch;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class KernelContextCommand extends Command
{
    protected $signature = 'kernel:context {query : Literal text to find} {--repo=* : Local Git repository path, repeat for up to five repositories} {--revision=HEAD : HEAD or full commit hash} {--content : Search and print matching source lines locally; may reveal sensitive data} {--json : Output JSON}';
    protected $description = 'Search local repository commit trees without calling a model';

    public function handle(RepositoryPathSearch $search): int
    {
        try {
            $content = (bool) $this->option('content');
            $results = $content
                ? $search->searchContent($this->option('repo'), (string) $this->argument('query'), (string) $this->option('revision'))
                : $search->search($this->option('repo'), (string) $this->argument('query'), (string) $this->option('revision'));
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        $scope = $content
            ? 'Local matching source lines may be sensitive; one line per text file, up to 25 shown, each clipped to 300 columns. Binary files and submodules excluded; nothing sent to a model.'
            : 'Tracked blob paths only; source content not inspected and submodules excluded.';
        if ($this->option('json')) {
            $this->line(json_encode(['scope' => $scope, 'repositories' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        $this->line($scope);
        foreach ($results as $result) {
            $this->newLine();
            $this->line(OutputFormatter::escape($result['repository']).' @ '.$result['commit']);
            if ($content) {
                $this->line($result['matches'].' matching text files (showing up to 25, one line each).');
                foreach ($result['results'] as $match) {
                    $this->line('  '.OutputFormatter::escape($match['path']).':'.$match['line'].'  '.OutputFormatter::escape($match['text']));
                }
            } else {
                $this->line($result['matches'].' matches among '.$result['scanned'].' tracked blob paths (showing up to 25).');
                foreach ($result['paths'] as $path) $this->line('  '.OutputFormatter::escape($path));
            }
            if ($result['omittedUnsafe'] > 0) $this->line($result['omittedUnsafe'].' paths with unsafe characters omitted.');
        }
        return self::SUCCESS;
    }
}
