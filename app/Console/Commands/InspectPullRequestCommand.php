<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ProofPatch\GitHub\PullRequestReader;
use ProofPatch\GitHub\PullRequestSnapshot;
use ProofPatch\Support\RuntimePaths;
use Symfony\Component\HttpClient\HttpClient;

final class InspectPullRequestCommand extends Command
{
    protected $signature = 'review:inspect {url? : GitHub pull request URL or shorthand (e.g. owner/repo#123)}';

    protected $description = 'Read a pull request and save an unverified review record';

    public function handle(): int
    {
        try {
            DB::table('review_jobs')->limit(1)->exists();
        } catch (\Throwable) {
            $this->error('Review database is unavailable. Create the database and run migrations first.');
            return self::FAILURE;
        }

        $target = $this->argument('url');
        if (!is_string($target) || trim($target) === '') {
            $target = $this->ask('Enter GitHub pull request (e.g. owner/repo#123 or full URL)');
            if (!is_string($target) || trim($target) === '') {
                $this->error('A pull request reference or URL is required.');
                return self::FAILURE;
            }
        }

        try {
            $token = getenv('GITHUB_TOKEN') ?: null;
            $pull = (new PullRequestReader(HttpClient::create(), $token))->read((string) $target);
            $project = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
            $directory = RuntimePaths::snapshots($project);
            $snapshot = (new PullRequestSnapshot($directory))->capture($pull);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }

        $this->line('Saved snapshot: '.$snapshot['id']);
        $result = $this->call('review:record', ['snapshot' => $snapshot['id']]);
        if ($result !== self::SUCCESS) {
            $this->error('The snapshot was saved but the review record was not created.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
