<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Services\MentionReviewListener;
use ProofPatch\Support\KernelCli;
use Symfony\Component\HttpClient\HttpClient;

/** Foreground opt-in, independent of autopilot; exits when the time budget ends. */
final class KernelMentionsCommand extends Command
{
    protected $signature = 'kernel:mentions {--repo= : Selected public owner/repo} {--minutes=10 : Runtime, 1-180 minutes} {--interval=15 : Poll interval in seconds} {--max-cycles= : Stop after this many polls} {--publish : Allow one bot review response per requested revision}';
    protected $description = 'Listen locally for maintainer PR review mentions';

    public function handle(): int
    {
        $token = GitHubTokenStore::getToken();
        $repo = trim((string) ($this->option('repo') ?: KernelCli::getSelectedRepo()));
        if ($token === null || $token === '' || !KernelCli::isValidRepo($repo)) {
            $this->error('Connect GitHub and select an authorized public repository first.');
            return self::FAILURE;
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('mention_review_attempts')) {
            $this->error('The mention listener database table is missing. Run php artisan migrate first.');
            return self::FAILURE;
        }
        $minutes = max(1, min(180, (int) $this->option('minutes')));
        $interval = max(2, min(3600, (int) $this->option('interval')));
        $maxCycles = $this->option('max-cycles') === null ? null : max(1, (int) $this->option('max-cycles'));
        $publish = (bool) $this->option('publish');
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 30);
        $deadline = time() + $minutes * 60;
        $listener = new MentionReviewListener(new GitHubAppClient(HttpClient::create()), new AutoReviewService());
        try {
            $baseline = $listener->baseline($token, $repo, $since);
        } catch (\Throwable $e) {
            $this->error('Mention listener could not start: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->line('Listening for new @proofpatcher review comments on '.$repo.'. Issue requests are not supported.');
        $this->line('The @pp review alias is accepted, but GitHub may notify the unrelated @pp account.');
        $this->line($publish ? 'The GitHub App bot may post one inline finding or a short review summary.' : 'Reviews stay local; no GitHub replies will be posted.');
        $cycles = 0;
        do {
            try {
                $nextSince = MentionReviewListener::advanceCursor($since, time());
                foreach ($listener->poll($token, $repo, $since, $startedAt, $baseline, $publish) as $result) {
                    $revision = isset($result['head']) ? ' at '.substr($result['head'], 0, 12) : '';
                    $publication = isset($result['publication']) ? '; bot comment: '.$result['publication'] : '; no GitHub response sent';
                    $this->line('#'.$result['number'].$revision.': '.$result['status'].$publication);
                    if (isset($result['url'])) $this->line($result['url']);
                }
                $since = $nextSince;
            } catch (\Throwable $e) {
                $this->error('Mention listener stopped: '.$e->getMessage());
                return self::FAILURE;
            }
            $cycles++;
            if (($maxCycles !== null && $cycles >= $maxCycles) || time() >= $deadline) break;
            if ($interval > 0) sleep($interval);
        } while (time() < $deadline);
        return self::SUCCESS;
    }
}
