<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ProofPatch\GitHub\PullRequestSnapshot;
use ProofPatch\Support\RuntimePaths;

final class RecordPullRequestSnapshotCommand extends Command
{
    protected $signature = 'review:record {snapshot : ID of an existing local snapshot}';

    protected $description = 'Record a saved pull request snapshot as not yet verified';

    public function handle(): int
    {
        $root = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        $directory = RuntimePaths::snapshots($root);
        $id = (string) $this->argument('snapshot');

        try {
            $saved = (new PullRequestSnapshot($directory))->load($id);
            $pull = $saved['snapshot']['pullRequest'] ?? null;
            if (!is_array($pull) || !is_string($pull['repository'] ?? null)
                || !preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $pull['repository'])
                || !is_int($pull['number'] ?? null) || $pull['number'] < 1
                || !is_string($pull['headSha'] ?? null) || !preg_match('/\A[a-f0-9]{40}\z/', $pull['headSha'])) {
                throw new \InvalidArgumentException('Snapshot does not contain a valid pull request.');
            }
        } catch (\InvalidArgumentException|\JsonException $error) {
            $this->error('Cannot record snapshot: '.$error->getMessage());
            return self::FAILURE;
        }

        $now = now();
        $created = DB::table('review_jobs')->insertOrIgnore([
            'snapshot_id' => $id,
            'snapshot_sha256' => $saved['sha256'],
            'repository' => $pull['repository'],
            'pull_number' => $pull['number'],
            'head_sha' => $pull['headSha'],
            'status' => 'not_verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $previous = DB::table('review_jobs')->where('snapshot_id', $id)->first();
        if ($created === 0) {
            if ($previous === null || !hash_equals($previous->snapshot_sha256, $saved['sha256'])
                || $previous->repository !== $pull['repository'] || (int) $previous->pull_number !== $pull['number']
                || $previous->head_sha !== $pull['headSha']) {
                $this->error('Snapshot conflicts with the existing review record.');
                return self::FAILURE;
            }
        }

        if ($previous === null) {
            $this->error('Review record could not be read.');
            return self::FAILURE;
        }
        if ($previous->status !== 'not_verified') {
            $this->error('Review has an unsupported status; inspect the record before continuing.');
            return self::FAILURE;
        }
        $this->line(($created === 1 ? 'Recorded' : 'Already recorded').' review #'.$previous->id.': '.$pull['repository'].' #'.$pull['number'].' — not verified.');
        return self::SUCCESS;
    }
}
