<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\AutomaticInlineCommentPublisher;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\GitHub\MentionReviewAuthorization;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Services\MentionReviewListener;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MentionReviewListenerTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private string $dir;
    private int $modelCalls = 0;
    private int $posts = 0;
    private ?string $postAuthorization = null;
    private ?array $posted = null;
    private ?string $postPath = null;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $app = $this->bootstrapAndMigrateIsolatedDatabase();
        $this->dir = sys_get_temp_dir().'/mention-review-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $app->useStoragePath($this->dir);
        GitHubTokenStore::save('mock-token-xyz', ['id' => 7, 'login' => 'owner'], []);
    }

    protected function tearDown(): void
    {
        GitHubTokenStore::clear();
        foreach (glob($this->dir.'/budget/*') ?: [] as $file) @unlink($file);
        @rmdir($this->dir.'/budget');
        @rmdir($this->dir.'/app');
        @rmdir($this->dir);
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function listener(array $options = []): MentionReviewListener
    {
        $private = $options['private'] ?? false;
        $commentId = $options['id'] ?? 123;
        $line = $options['line'] ?? 1;
        $comment = [
            'id' => $commentId, 'body' => $options['body'] ?? '@proofpatcher review',
            'issue_url' => 'https://api.github.com/repos/acme/repo/issues/42',
            'created_at' => '2026-11-02T10:00:01Z',
            'updated_at' => $options['updated'] ?? '2026-11-02T10:00:01Z',
            'user' => ['id' => 8, 'login' => 'maintainer', 'type' => $options['type'] ?? 'User'],
        ];
        $pull = ['number' => 42, 'title' => 'Fix', 'state' => 'open', 'head' => ['sha' => self::HEAD],
            'base' => ['sha' => self::BASE, 'repo' => ['private' => $private, 'visibility' => $private ? 'private' : 'public']],
            'changed_files' => 1];
        $files = [['filename' => 'src/Example.php', 'additions' => 1, 'deletions' => 1,
            'patch' => "@@ -{$line},1 +{$line},1 @@\n-old\n+new"]];
        $permissionsChecked = 0;
        $grantsChecked = 0;
        $pullsChecked = 0;
        $commentReads = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $request) use ($options, $comment, $commentId, $pull, $files, $private, &$permissionsChecked, &$grantsChecked, &$pullsChecked, &$commentReads): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ($method === 'POST' && in_array($path, ['/repos/acme/repo/pulls/42/comments', '/repos/acme/repo/pulls/42/reviews'], true)) {
                $this->posts++;
                $this->postAuthorization = implode(' ', $request['normalized_headers']['authorization'] ?? []);
                $this->postPath = $path;
                $this->posted = json_decode($request['body'], true, 16, JSON_THROW_ON_ERROR);
                return new MockResponse(($options['postFail'] ?? false) ? '{}' : '{"id":456}', ['http_code' => ($options['postFail'] ?? false) ? 500 : (str_ends_with($path, '/reviews') ? ($options['reviewStatus'] ?? 200) : 201)]);
            }
            if ($method !== 'GET') throw new \RuntimeException('Unexpected write.');
            if ($path === '/repos/acme/repo/pulls/42' && ($options['notPull'] ?? false)) return new MockResponse('', ['http_code' => 404]);
            if ($path === '/repos/acme/repo/collaborators/maintainer/permission') ++$permissionsChecked;
            if ($path === '/user/installations/1/repositories') ++$grantsChecked;
            if ($path === '/repos/acme/repo/pulls/42') ++$pullsChecked;
            if ($path === '/repos/acme/repo/issues/comments/'.$commentId) ++$commentReads;
            $currentPull = $pull;
            if ((($options['stale'] ?? false) && $pullsChecked >= 5) || (isset($options['staleAt']) && $pullsChecked >= $options['staleAt'])) $currentPull['head']['sha'] = str_repeat('c', 40);
            $payload = match ($path) {
                '/user' => ['id' => 7, 'login' => 'acme'],
                '/user/installations' => ['installations' => [['id' => 1]]],
                '/user/installations/1/repositories' => ['repositories' => ($options['grantRevoked'] ?? false) && $grantsChecked > 1 ? [] : [[
                    'id' => 1, 'name' => 'repo', 'full_name' => 'acme/repo', 'private' => $private,
                    'visibility' => $private ? 'private' : 'public']]],
                '/repos/acme/repo' => ['full_name' => 'acme/repo', 'private' => false, 'visibility' => 'public'],
                '/repos/acme/repo/issues/comments' => [$comment],
                '/repos/acme/repo/issues/comments/'.$commentId => (($options['changed'] ?? false) || (isset($options['revokeAtComment']) && $commentReads >= $options['revokeAtComment'])) ? array_replace($comment, ['body' => 'changed']) : $comment,
                '/repos/acme/repo/collaborators/maintainer/permission' => ['permission' => ($options['permissionRevoked'] ?? false) && $permissionsChecked > 1 ? 'read' : ($options['permission'] ?? 'write'), 'user' => ['id' => 8, 'login' => 'maintainer']],
                '/repos/acme/repo/pulls/42' => $currentPull,
                '/repos/acme/repo/pulls/42/files' => $files,
                default => throw new \RuntimeException('Unexpected request '.$path),
            };
            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });
        if (!is_dir($this->dir.'/budget')) mkdir($this->dir.'/budget', 0700, true);
        $budget = new RequestBudget($this->dir.'/budget', 500000, 10);
        $model = new MockHttpClient(function (string $method, string $url, array $request) use ($options, $line): MockResponse {
            $this->modelCalls++;
            self::assertStringNotContainsString('@proofpatcher', (string) ($request['body'] ?? ''));
            $concern = (bool) ($options['concern'] ?? false);
            $answer = ['summary' => 'Review complete.', 'riskLevel' => 'low', 'recommendation' => $concern ? 'discuss' : 'approve',
                'fileFindings' => [['path' => 'src/Example.php', 'status' => $concern ? 'concern' : 'approval', 'lineHint' => $line,
                    'comment' => $options['findingComment'] ?? ($concern ? 'Could this handle empty input?' : 'The change looks good.'),
                    'suggestion' => $options['suggestion'] ?? null]]];
            return new MockResponse(json_encode(['model' => PullRequestReview::MODEL, 'choices' => [['finish_reason' => 'stop',
                'message' => ['content' => json_encode($answer)]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40]]));
        });
        $github = new GitHubAppClient($client);
        $service = new AutoReviewService(github: $github, patchPreviewer: new PublicPatchPreview($client),
            reviewAgent: new PullRequestReview($model, $budget), budget: $budget, basetenKey: 'valid-test-baseten-key-123456789');
        $publisher = new AutomaticInlineCommentPublisher(DB::connection()->getPdo(), $client,
            static fn (string $token): bool => true, static fn (string $token, string $repository): string => 'installation-token');
        return new MentionReviewListener($github, $service, $publisher);
    }

    public function test_maintainer_mention_reviews_locally_with_autopilot_off_and_does_not_replay(): void
    {
        self::assertFalse(RepositoryAutoReviewSetting::isRepoEnabled('acme/repo'));
        $listener = $this->listener();
        self::assertSame([['number' => 42, 'status' => 'completed', 'head' => self::HEAD]], $listener->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z'));
        self::assertSame([], $listener->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z'));
        self::assertSame(1, $this->modelCalls);
        self::assertSame('completed', DB::table('mention_review_attempts')->value('status'));
    }

    public function test_outsider_or_changed_comment_never_calls_model(): void
    {
        foreach ([['permission' => 'read'], ['changed' => true], ['permissionRevoked' => true], ['grantRevoked' => true], ['stale' => true], ['notPull' => true]] as $options) {
            DB::table('mention_review_attempts')->delete();
            DB::table('auto_review_results')->delete();
            $results = $this->listener($options)->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z');
            self::assertContains($results[0]['status'], ['rejected', 'skipped_unauthorized', 'failed']);
            self::assertSame(0, DB::table('auto_review_results')->count(), 'Preflight must release its revision claim.');
        }
        self::assertSame(0, $this->modelCalls);
    }

    public function test_failed_mention_does_not_block_a_later_authorized_mention_for_same_revision(): void
    {
        self::assertSame('skipped_unauthorized', $this->listener(['changed' => true])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z')[0]['status']);
        self::assertSame('completed', $this->listener(['id' => 124])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z')[0]['status']);
        self::assertSame(1, $this->modelCalls);
        self::assertSame(2, DB::table('mention_review_attempts')->count());
    }

    public function test_same_second_new_comment_is_handled_but_baseline_is_not(): void
    {
        $listener = $this->listener();
        $started = '2026-11-02T10:00:01Z';
        self::assertSame([123], $listener->baseline('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z'));
        self::assertSame([], $listener->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', $started, [123]));
        self::assertSame('completed', $listener->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', $started)[0]['status']);
        self::assertSame(1, $this->modelCalls);
    }

    public function test_cursor_advances_with_overlap_without_moving_backwards(): void
    {
        $time = strtotime('2026-11-02T10:01:00Z');
        self::assertSame('2026-11-02T10:00:30Z', MentionReviewListener::advanceCursor('2026-11-02T10:00:00Z', $time));
        self::assertSame('2026-11-02T10:00:40Z', MentionReviewListener::advanceCursor('2026-11-02T10:00:40Z', $time));
    }

    public function test_inline_response_requires_explicit_publish_and_uses_the_bot_with_autopilot_off(): void
    {
        $result = $this->listener(['concern' => true])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
        self::assertFalse(RepositoryAutoReviewSetting::isRepoEnabled('acme/repo'));
        self::assertSame('posted', $result[0]['publication']);
        self::assertSame('https://github.com/acme/repo/pull/42#discussion_r456', $result[0]['url']);
        self::assertSame('/repos/acme/repo/pulls/42/comments', $this->postPath);
        self::assertStringContainsString('installation-token', $this->postAuthorization);
        self::assertStringNotContainsString('mock-token-xyz', $this->postAuthorization);
        self::assertSame(self::HEAD, $this->posted['commit_id']);
        self::assertSame('one_inline_comment', \ProofPatch\Models\AutoReviewResult::first()->getRawOriginal('published_status'));
        self::assertSame(1, $this->posts);
        $duplicate = $this->listener(['concern' => true, 'id' => 124])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
        self::assertSame('failed', $duplicate[0]['publication']);
        self::assertSame(1, $this->posts);
        self::assertSame(1, $this->modelCalls);
    }

    public function test_without_publish_flag_the_review_stays_local(): void
    {
        $result = $this->listener(['concern' => true])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z');
        self::assertSame('completed', $result[0]['status']);
        self::assertArrayNotHasKey('publication', $result[0]);
        self::assertSame(0, $this->posts);
    }

    public function test_xss_example_remains_inline_but_cannot_be_rendered_as_html(): void
    {
        $comment = 'Reflected XSS via unescaped $query in HTML output. Allows attacker-supplied query like `<script>alert(1)</script>` to execute; escape with htmlspecialchars.';
        $suggestion = '    $safe = htmlspecialchars($query);';
        $result = $this->listener(['concern' => true, 'line' => 4, 'findingComment' => $comment, 'suggestion' => $suggestion])
            ->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
        self::assertSame('posted', $result[0]['publication']);
        self::assertSame('/repos/acme/repo/pulls/42/comments', $this->postPath);
        self::assertSame(4, $this->posted['line']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->posted['body']);
        self::assertStringContainsString('```suggestion' . "\n" . $suggestion, $this->posted['body']);
        $html = (new \League\CommonMark\CommonMarkConverter())->convert($this->posted['body'])->getContent();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('script', $html);
    }

    public function test_unsafe_markdown_links_and_images_are_not_in_fallback_reviews(): void
    {
        foreach (['[view](//evil.example/path)', '![image](//evil.example/pixel)', '[x](mailto:someone)', '[x][ref]', '[ref]: //evil.example'] as $unsafe) {
            DB::table('mention_review_attempts')->delete();
            DB::table('auto_review_results')->delete();
            DB::table('automatic_inline_attempts')->delete();
            $result = $this->listener(['concern' => true, 'findingComment' => $unsafe])
                ->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
            self::assertSame('posted', $result[0]['publication']);
            self::assertSame('/repos/acme/repo/pulls/42/reviews', $this->postPath);
            $html = (new \League\CommonMark\CommonMarkConverter())->convert($this->posted['body'])->getContent();
            self::assertStringNotContainsString('<a ', $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('evil.example', $html);
        }
    }

    public function test_no_inline_concern_posts_a_careful_bot_review_summary(): void
    {
        $result = $this->listener()->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
        self::assertSame('posted', $result[0]['publication']);
        self::assertSame('https://github.com/acme/repo/pull/42#pullrequestreview-456', $result[0]['url']);
        self::assertSame('/repos/acme/repo/pulls/42/reviews', $this->postPath);
        self::assertSame('COMMENT', $this->posted['event']);
        self::assertStringContainsString('Nothing needed flagging inline this round.', $this->posted['body']);
        self::assertSame('review_summary', \ProofPatch\Models\AutoReviewResult::first()->published_status);
        self::assertSame('review_summary', \ProofPatch\Models\AutoReviewResult::first()->getRawOriginal('published_status'));
    }

    public function test_revoked_mention_and_stale_revision_fail_closed_before_writing(): void
    {
        foreach ([['revokeAtComment' => 4], ['staleAt' => 10]] as $options) {
            DB::table('mention_review_attempts')->delete();
            DB::table('auto_review_results')->delete();
            DB::table('automatic_inline_attempts')->delete();
            $result = $this->listener($options + ['concern' => true])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
            self::assertSame('completed', $result[0]['status']);
            self::assertContains($result[0]['publication'], ['failed', 'not_posted']);
            self::assertSame(0, $this->posts);
            self::assertSame(0, DB::table('automatic_inline_attempts')->count());
        }
    }

    public function test_uncertain_post_is_not_retried(): void
    {
        $result = $this->listener(['concern' => true, 'postFail' => true])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
        self::assertSame('unknown', $result[0]['publication']);
        $duplicate = $this->listener(['concern' => true, 'id' => 124])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
        self::assertSame('failed', $duplicate[0]['publication']);
        self::assertSame(1, $this->posts);
        self::assertSame('uncertain', DB::table('automatic_inline_attempts')->value('status'));
    }

    public function test_unexpected_review_response_remains_uncertain(): void
    {
        $result = $this->listener(['reviewStatus' => 201])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z', null, [], true);
        self::assertSame('unknown', $result[0]['publication']);
        self::assertSame('uncertain', DB::table('automatic_inline_attempts')->value('status'));
        self::assertSame(1, $this->posts);
    }

    public function test_explicit_pp_alias_is_accepted(): void
    {
        $result = $this->listener(['body' => '@pp review'])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z');
        self::assertSame('completed', $result[0]['status']);
        self::assertSame(0, $this->posts);
    }

    public function test_publication_authorization_is_bound_to_token_and_connected_user(): void
    {
        $request = new MentionReviewAuthorization(new GitHubAppClient(new MockHttpClient()), 'mock-token-xyz', 7,
            'acme/repo', 42, 123, 8, 'maintainer', '2026-11-02T10:00:01Z');
        self::assertFalse($request->allowsForUser('different-token', 7, 'acme/repo', 42, self::HEAD, self::BASE));
        self::assertFalse($request->allowsForUser('mock-token-xyz', 9, 'acme/repo', 42, self::HEAD, self::BASE));
    }

    public function test_bot_edited_and_inexact_mentions_are_ignored(): void
    {
        foreach ([['type' => 'Bot'], ['updated' => '2026-11-02T10:00:02Z'], ['body' => '@proofpatcher review everything']] as $options) {
            self::assertSame([], $this->listener($options)->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z'));
        }
        self::assertSame(0, DB::table('mention_review_attempts')->count());
        self::assertSame(0, $this->modelCalls);
    }

    public function test_private_repository_is_rejected_before_polling(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->listener(['private' => true])->poll('mock-token-xyz', 'acme/repo', '2026-11-02T10:00:00Z');
    }
}
