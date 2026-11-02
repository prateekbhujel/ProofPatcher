<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Services\ReviewFollowupTracker;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReviewFollowupTrackerTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private const REPO = 'acme/repo';
    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        RepositoryAutoReviewSetting::setRepoEnabled(self::REPO, true);
        DB::table('automatic_inline_attempts')->insert([
            'revision_key' => str_repeat('c', 64), 'user_id' => 7, 'repository' => self::REPO,
            'pull_number' => 10, 'status' => 'posted', 'comment_id' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function github(array $pages, bool $private = false, bool $revoked = false, bool $grantRemoved = false, bool $changeAfterFetch = false): GitHubAppClient
    {
        $pullReads = 0;
        $http = new MockHttpClient(static function (string $method, string $url) use ($pages, $private, $revoked, $grantRemoved, $changeAfterFetch, &$pullReads): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/repos/acme/repo/pulls') return new MockResponse(json_encode([[
                'number' => 10, 'title' => 'test', 'state' => 'open', 'head' => ['sha' => self::HEAD],
                'base' => ['sha' => self::BASE, 'repo' => ['private' => $private, 'visibility' => $private ? 'private' : 'public']],
            ]]));
            if ($path === '/repos/acme/repo/pulls/10') { $pullReads++; return new MockResponse(json_encode([
                'number' => 10, 'state' => 'open', 'head' => ['sha' => self::HEAD],
                'base' => ['sha' => self::BASE, 'repo' => ['private' => $private || ($changeAfterFetch && $pullReads > 1), 'visibility' => $private || ($changeAfterFetch && $pullReads > 1) ? 'private' : 'public']],
            ])); }
            if ($path === '/repos/acme/repo/pulls/10/comments') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $data = $pages[(int)($query['page'] ?? 1)] ?? [];
                return $data === 'failure' ? new MockResponse('', ['http_code' => 503]) : new MockResponse(json_encode($data));
            }
            if ($path === '/user') return new MockResponse(json_encode(['id' => 7, 'login' => 'acme']), ['http_code' => $revoked ? 401 : 200]);
            if ($path === '/user/installations') return new MockResponse(json_encode(['installations' => [['id' => 1]]]));
            if ($path === '/user/installations/1/repositories') return new MockResponse(json_encode(['repositories' => $grantRemoved ? [] : [[
                'id' => 10, 'name' => 'repo', 'full_name' => self::REPO, 'private' => $private, 'visibility' => $private ? 'private' : 'public',
            ]]]));
            throw new \RuntimeException('Unexpected request');
        });
        return new GitHubAppClient($http);
    }

    private function tracker(array $pages, bool $private = false, bool $revoked = false): ReviewFollowupTracker
    {
        return new ReviewFollowupTracker($this->github($pages, $private, $revoked));
    }

    private function detect(ReviewFollowupTracker $tracker): array
    {
        return $tracker->detect('test-token', 7, self::REPO, 10, self::HEAD, self::BASE);
    }

    public function test_human_reply_to_our_bot_is_actionable_and_deduplicated_without_storing_text(): void
    {
        $comments = [
            ['id' => 100, 'user' => ['type' => 'Bot']],
            ['id' => 101, 'in_reply_to_id' => 100, 'user' => ['type' => 'User'], 'body' => 'untrusted text'],
            ['id' => 102, 'in_reply_to_id' => 100, 'user' => ['type' => 'Bot']],
            ['id' => 103, 'in_reply_to_id' => 999, 'user' => ['type' => 'User']],
        ];
        $expected = ['https://github.com/acme/repo/pull/10#discussion_r101'];
        self::assertSame($expected, $this->detect($this->tracker([1 => $comments])));
        self::assertSame($expected, $this->detect($this->tracker([1 => $comments])));
        self::assertSame(1, DB::table('review_followups')->count());
        self::assertStringNotContainsString('untrusted text', json_encode(DB::table('review_followups')->first()));
    }

    public function test_non_bot_root_and_opt_out_do_not_track(): void
    {
        $comments = [['id' => 100, 'user' => ['type' => 'User']], ['id' => 101, 'in_reply_to_id' => 100, 'user' => ['type' => 'User']]];
        self::assertSame([], $this->detect($this->tracker([1 => $comments])));
        RepositoryAutoReviewSetting::setRepoEnabled(self::REPO, false);
        self::assertSame([], $this->detect($this->tracker([])));
        self::assertSame(0, DB::table('review_followups')->count());
    }

    public function test_private_or_revoked_access_cannot_store_a_reply(): void
    {
        $comments = [['id' => 100, 'user' => ['type' => 'Bot']], ['id' => 101, 'in_reply_to_id' => 100, 'user' => ['type' => 'User']]];
        foreach ([[$this->tracker([1 => $comments], true), 'visibility'], [$this->tracker([1 => $comments], false, true), 'HTTP 401']] as [$tracker, $message]) {
            try { $this->detect($tracker); self::fail('Expected rejection'); } catch (\RuntimeException $e) { self::assertStringContainsString($message, $e->getMessage()); }
            self::assertSame(0, DB::table('review_followups')->count());
        }
    }

    public function test_pagination_must_be_complete_before_any_reply_is_stored(): void
    {
        $page = [['id' => 100, 'user' => ['type' => 'Bot']], ['id' => 101, 'in_reply_to_id' => 100, 'user' => ['type' => 'User']]];
        for ($i = 0; $i < 98; $i++) $page[] = ['id' => 200 + $i, 'user' => ['type' => 'User']];
        self::assertCount(1, $this->detect($this->tracker([1 => $page, 2 => []])));
        DB::table('review_followups')->delete();
        $pages = [1 => $page];
        for ($p = 2; $p <= 5; $p++) { $pages[$p] = []; for ($i = 0; $i < 100; $i++) $pages[$p][] = ['id' => 1000 * $p + $i, 'user' => ['type' => 'User']]; }
        try { $this->detect($this->tracker($pages)); self::fail('Expected truncated list rejection'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('exceeds', $e->getMessage()); }
        self::assertSame(0, DB::table('review_followups')->count());
    }

    public function test_page_failure_changed_visibility_and_removed_grant_fail_closed(): void
    {
        $page = [['id' => 100, 'user' => ['type' => 'Bot']], ['id' => 101, 'in_reply_to_id' => 100, 'user' => ['type' => 'User']]];
        $first = $page;
        for ($i = 0; $i < 98; $i++) $first[] = ['id' => 200 + $i, 'user' => ['type' => 'User']];
        $clients = [
            $this->github([1 => $first, 2 => 'failure']),
            $this->github([1 => $page], false, false, false, true),
            $this->github([1 => $page], false, false, true),
        ];
        foreach ($clients as $client) {
            try { $this->detect(new ReviewFollowupTracker($client)); self::fail('Expected failure'); }
            catch (\RuntimeException) { self::assertSame(0, DB::table('review_followups')->count()); }
        }
    }

    public function test_poll_surfaces_reply_without_repeating_model_review(): void
    {
        AutoReviewResult::create(['repository' => self::REPO, 'pull_number' => 10,
            'head_sha' => self::HEAD, 'base_sha' => self::BASE, 'status' => 'completed', 'is_public' => true]);
        $comments = [['id' => 100, 'user' => ['type' => 'Bot']], ['id' => 101, 'in_reply_to_id' => 100, 'user' => ['type' => 'User']]];
        $service = new AutoReviewService(github: $this->github([1 => $comments]));
        $result = $service->poll('test-token', self::REPO);
        self::assertSame(['https://github.com/acme/repo/pull/10#discussion_r101'], $result['followups']);
        self::assertSame(1, $result['reviewsSkipped']);
        self::assertSame(0, $result['reviewsCompleted']);
        self::assertSame([], $result['errors']);
    }
}
