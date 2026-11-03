<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use ProofPatch\GitHub\PullRequestSnapshot;
use ProofPatch\Support\RuntimePaths;

final class ReviewJobController
{
    public function show(string $reviewId): JsonResponse
    {
        $review = DB::table('review_jobs')->where('id', $reviewId)->first();
        if ($review === null) {
            return response()->json(['message' => 'Review not found.'], 404)->header('Cache-Control', 'no-store');
        }

        $project = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        $directory = RuntimePaths::snapshots($project);
        $store = new PullRequestSnapshot($directory);
        try {
            $store->verify($review->snapshot_id, $review->snapshot_sha256);
            $saved = $store->load($review->snapshot_id);
        } catch (\Throwable) {
            return response()->json(['message' => 'Saved snapshot is unavailable or does not match the review record.'], 409)
                ->header('Cache-Control', 'no-store');
        }

        if (!PullRequestSnapshot::isExplicitlyPublic($saved)) {
            return response()->json(['message' => 'Review not found.'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'id' => $review->id,
            'repository' => $review->repository,
            'pull_number' => $review->pull_number,
            'head_sha' => $review->head_sha,
            'status' => $review->status,
            'snapshot_id' => $review->snapshot_id,
            'snapshot_sha256' => $review->snapshot_sha256,
            'snapshot_integrity' => 'matches_saved_digest',
        ])->header('Cache-Control', 'no-store');
    }
}
