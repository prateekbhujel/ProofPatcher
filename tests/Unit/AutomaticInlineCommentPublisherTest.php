<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\AutomaticInlineCommentPublisher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AutomaticInlineCommentPublisherTest extends TestCase
{
    private PDO $db;
    private int $posts = 0;
    private int $users = 0;
    private bool $revokeAtSecondCheck = false;
    private bool $stale = false;
    private bool $partial = false;
    private bool $postFailure = false;
    private bool $optOutBeforePost = false;
    private bool $reviewOptOutBeforePost = false;
    private ?array $posted = null;
    private ?string $postAuthorization = null;
    private bool $missingBot = false;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE automatic_inline_settings (user_id INTEGER, repository TEXT COLLATE NOCASE, enabled INTEGER DEFAULT 0, PRIMARY KEY(user_id, repository))');
        $this->db->exec('CREATE TABLE automatic_inline_attempts (revision_key TEXT PRIMARY KEY, user_id INTEGER, repository TEXT, pull_number INTEGER, status TEXT, comment_id INTEGER)');
        $this->db->exec('CREATE TABLE repository_auto_review_settings (repository TEXT, enabled INTEGER DEFAULT 0)');
        $this->db->exec("INSERT INTO repository_auto_review_settings VALUES ('owner/repo', 1)");
    }

    private function enable(): void
    {
        $this->db->exec("INSERT INTO automatic_inline_settings VALUES (7, 'owner/repo', 1)");
    }

    private function pull(): array
    {
        return ['repository' => 'owner/repo', 'number' => 12, 'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40)];
    }

    private function finding(): array
    {
        return ['status' => 'concern', 'commentable' => true, 'path' => 'src/file.php', 'lineHint' => 1, 'comment' => 'Could this handle empty input?'];
    }

    private function client(): AutomaticInlineCommentPublisher
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ($method === 'POST') {
                $this->posts++;
                $this->postAuthorization = implode(' ', $options['normalized_headers']['authorization'] ?? []);
                $this->posted = json_decode($options['body'], true, 16, JSON_THROW_ON_ERROR);
                return new MockResponse($this->postFailure ? '{}' : '{"id":123}', ['http_code' => $this->postFailure ? 500 : 201]);
            }
            if ($path === '/user') {
                $this->users++;
                if ($this->optOutBeforePost && $this->users === 2) $this->db->exec('UPDATE automatic_inline_settings SET enabled = 0');
                if ($this->reviewOptOutBeforePost && $this->users === 2) $this->db->exec('UPDATE repository_auto_review_settings SET enabled = 0');
                return new MockResponse(json_encode(['id' => $this->revokeAtSecondCheck && $this->users > 1 ? 8 : 7, 'login' => 'owner']));
            }
            if ($path === '/user/installations') return new MockResponse(json_encode(['installations' => [['id' => 1]]]));
            if ($path === '/user/installations/1/repositories') return new MockResponse(json_encode(['repositories' => [[
                'id' => 2, 'name' => 'repo', 'full_name' => 'owner/repo', 'owner' => ['login' => 'owner'], 'private' => false, 'visibility' => 'public',
            ]]]));
            if ($path === '/repos/owner/repo/pulls/12') return new MockResponse(json_encode([
                'number' => 12, 'state' => 'open', 'changed_files' => 1,
                'head' => ['sha' => str_repeat($this->stale ? 'c' : 'a', 40)],
                'base' => ['sha' => str_repeat('b', 40), 'repo' => ['private' => false, 'visibility' => 'public']],
            ]));
            if ($path === '/repos/owner/repo/pulls/12/files') return new MockResponse(json_encode([[
                'filename' => 'src/file.php', 'patch' => "@@ -1,1 +1,1 @@\n-old\n+new",
                'additions' => $this->partial ? 2 : 1, 'deletions' => 1,
            ]]));
            throw new RuntimeException('Unexpected request: '.$path);
        });
        return new AutomaticInlineCommentPublisher($this->db, $http, static fn (string $token): bool => true,
            fn (string $token, string $repo): string => $this->missingBot ? $token : 'installation-token');
    }

    public function testOptOutMakesNoRequest(): void
    {
        $this->assertNull($this->client()->publish('token', 7, $this->pull(), [$this->finding()]));
        $this->assertSame(0, $this->users);
    }

    public function testNoUnanchoredOrUnsafeModelTextIsPosted(): void
    {
        $this->enable();
        foreach ([['commentable' => false], ['comment' => '@someone please look'], ['comment' => 'see https://example.com'],
            ['comment' => '[view](//evil.example/path)'], ['comment' => '![image](//evil.example/pixel)'],
            ['comment' => '[x](mailto:someone)'], ['comment' => '[x][ref]'], ['comment' => '[ref]: //evil.example']] as $change) {
            $this->assertNull($this->client()->publish('token', 7, $this->pull(), [array_replace($this->finding(), $change)]));
        }
        $this->assertSame(0, $this->posts);
        $this->assertSame(0, $this->users);
    }

    public function test_html_in_finding_is_escaped_before_markdown_rendering(): void
    {
        $this->enable();
        $finding = array_replace($this->finding(), ['comment' => 'Unsafe HTML: <img src=x onerror=alert(1)> & <script>alert(1)</script>.']);
        $result = $this->client()->publish('token', 7, $this->pull(), [$finding]);
        $this->assertSame(123, $result['id']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $this->posted['body']);
        $html = (new \League\CommonMark\CommonMarkConverter())->convert($this->posted['body'])->getContent();
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testStaleAndPartialDiffsDoNotPost(): void
    {
        $this->enable();
        foreach (['stale', 'partial'] as $case) {
            $this->$case = true;
            try { $this->client()->publish('token', 7, $this->pull(), [$this->finding()]); $this->fail('Expected a refusal.'); }
            catch (RuntimeException) { $this->assertSame(0, $this->posts); }
            $this->$case = false;
        }
    }

    public function testRevokedIdentityBeforePostingDoesNotPost(): void
    {
        $this->enable();
        $this->revokeAtSecondCheck = true;
        try { $this->client()->publish('token', 7, $this->pull(), [$this->finding()]); $this->fail('Expected a refusal.'); }
        catch (RuntimeException) { $this->assertSame(0, $this->posts); }
        $this->assertFalse($this->db->query('SELECT status FROM automatic_inline_attempts')->fetchColumn());
        $this->revokeAtSecondCheck = false;
        $this->users = 0;
        $this->assertSame(123, $this->client()->publish('token', 7, $this->pull(), [$this->finding()])['id']);
        $this->assertSame(1, $this->posts);
    }

    public function testOptOutImmediatelyBeforePostIsHonored(): void
    {
        $this->enable();
        $this->optOutBeforePost = true;
        $this->assertNull($this->client()->publish('token', 7, $this->pull(), [$this->finding()]));
        $this->assertSame(0, $this->posts);
        $this->assertFalse($this->db->query('SELECT status FROM automatic_inline_attempts')->fetchColumn());
    }

    public function testReviewOptOutImmediatelyBeforePostIsHonored(): void
    {
        $this->enable();
        $this->reviewOptOutBeforePost = true;
        $this->assertNull($this->client()->publish('token', 7, $this->pull(), [$this->finding()]));
        $this->assertSame(0, $this->posts);
        $this->assertFalse($this->db->query('SELECT status FROM automatic_inline_attempts')->fetchColumn());
    }

    public function testFailedPostIsUncertainAndCannotRetry(): void
    {
        $this->enable();
        $this->postFailure = true;
        foreach ([1, 2] as $_) {
            try { $this->client()->publish('token', 7, $this->pull(), [$this->finding()]); $this->fail('Expected a refusal.'); }
            catch (RuntimeException) { $this->assertSame(1, $this->posts); }
        }
        $this->assertSame('uncertain', $this->db->query('SELECT status FROM automatic_inline_attempts')->fetchColumn());
        $this->assertStringContainsString('Automated ProofPatcher', $this->posted['body']);
    }

    public function testSafeSuggestionIsPostedInsideFencedBlock(): void
    {
        $this->enable();
        $finding = array_replace($this->finding(), ['suggestion' => "    \$total += \$item['price'] * \$item['qty'];"]);
        $this->client()->publish('token', 7, $this->pull(), [$finding]);
        $this->assertStringContainsString('Could this handle empty input?', $this->posted['body']);
        $this->assertStringContainsString("```suggestion\n    \$total += \$item['price'] * \$item['qty'];", $this->posted['body']);
        $this->assertStringContainsString('**issue:**', $this->posted['body']);
        $this->assertStringContainsString('installation-token', $this->postAuthorization);
        $this->assertStringNotContainsString('Bearer token', $this->postAuthorization);
    }

    public function testNeverFallsBackToUserTokenForPosting(): void
    {
        $this->enable();
        $this->missingBot = true;
        try { $this->client()->publish('token', 7, $this->pull(), [$this->finding()]); $this->fail('Expected a refusal.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('bot token', $e->getMessage()); }
        $this->assertSame(0, $this->posts);
    }

    public function testBlockingDecorationFollowsVerdict(): void
    {
        $this->enable();
        $this->client()->publish('token', 7, $this->pull(), [$this->finding()], 'request_changes');
        $this->assertStringContainsString('**issue:**', $this->posted['body']);
        $this->assertStringNotContainsString('non-blocking', $this->posted['body']);
        $this->db->exec('DELETE FROM automatic_inline_attempts');
        $this->posts = 0;
        $this->client()->publish('token', 7, $this->pull(), [$this->finding()], 'approve');
        $this->assertStringContainsString('**issue (non-blocking):**', $this->posted['body']);
    }

    public function testDangerousSuggestionIsDroppedButCommentStillPosts(): void
    {
        $this->enable();
        foreach (['see https://evil.test/x', 'ping @owner', "```\nbreak out", '<img src=x onerror=1>', "line\x01break", "<?php\nfunction replacement() {}", '?>', "first line\nsecond line"] as $bad) {
            $this->db->exec('DELETE FROM automatic_inline_attempts');
            $this->posts = 0;
            $finding = array_replace($this->finding(), ['suggestion' => $bad]);
            $this->client()->publish('token', 7, $this->pull(), [$finding]);
            $this->assertStringContainsString('Could this handle empty input?', $this->posted['body']);
            $this->assertStringNotContainsString('```suggestion', $this->posted['body']);
        }
    }

    public function testSuccessfulPostIsLimitedToOnePerRevisionEvenIfTextChanges(): void
    {
        $this->enable();
        $result = $this->client()->publish('token', 7, $this->pull(), [$this->finding()]);
        $this->assertSame(123, $result['id']);
        $this->assertSame('posted', $this->db->query('SELECT status FROM automatic_inline_attempts')->fetchColumn());
        try {
            $this->client()->publish('token', 7, $this->pull(), [array_replace($this->finding(), ['comment' => 'Different concern'])]);
            $this->fail('Expected duplicate revision to be refused.');
        } catch (RuntimeException) {
            $this->assertSame(1, $this->posts);
        }
    }
}
