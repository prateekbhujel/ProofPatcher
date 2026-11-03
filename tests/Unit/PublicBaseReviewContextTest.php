<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\PublicBaseReviewContext;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PublicBaseReviewContextTest extends TestCase
{
    private function pull(): array
    {
        return ['repository' => 'example/project', 'number' => 7, 'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40), 'isPrivate' => false];
    }

    private function preview(): array
    {
        return $this->pull() + ['filesTruncated' => false, 'files' => [['name' => 'src/Foo.php', 'status' => 'available', 'patch' => "@@ -2,1 +2,1 @@\n-old\n+new"]]];
    }

    private function responses(string $mode = '100644', ?string $content = null, bool $truncated = false): array
    {
        $content ??= "first\nold\nlast\n";
        $base = str_repeat('b', 40);
        $root = str_repeat('c', 40);
        $dir = str_repeat('d', 40);
        $blob = sha1('blob '.strlen($content)."\0".$content);
        return [
            ['sha' => $base, 'tree' => ['sha' => $root]],
            ['sha' => $root, 'truncated' => false, 'tree' => [['path' => 'src', 'type' => 'tree', 'mode' => '040000', 'sha' => $dir]]],
            ['sha' => $dir, 'truncated' => $truncated, 'tree' => [['path' => 'Foo.php', 'type' => 'blob', 'mode' => $mode, 'sha' => $blob]]],
            ['sha' => $blob, 'size' => strlen($content), 'encoding' => 'base64', 'content' => base64_encode($content)],
        ];
    }

    private function client(array $responses): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses) {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://api.github.com/repos/example/project/git/', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertStringNotContainsString('Authorization:', implode(' ', $options['headers'] ?? []));
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
    }

    public function testVerifiedBaseSnippetIsBoundToExactPrAndCommit(): void
    {
        $out = (new PublicBaseReviewContext($this->client($this->responses())))->fetch($this->pull(), $this->preview(), 'src/Foo.php');
        self::assertSame(str_repeat('b', 40), $out['baseSha']);
        self::assertSame('src/Foo.php', $out['path']);
        self::assertSame([['startLine' => 1, 'endLine' => 3, 'text' => "first\nold\nlast"]], $out['snippets']);
    }

    public function testUnknownFileAndPrivatePreviewFailBeforeNetwork(): void
    {
        $service = new PublicBaseReviewContext(new MockHttpClient());
        try { $service->fetch($this->pull(), $this->preview(), 'src/Other.php'); self::fail('outside diff'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        $private = $this->preview(); $private['isPrivate'] = true;
        $this->expectException(\InvalidArgumentException::class);
        $service->fetch($this->pull(), $private, 'src/Foo.php');
    }

    public function testSymlinksAndIncompleteTreesFailClosed(): void
    {
        foreach ([['120000', false], ['100644', true]] as [$mode, $truncated]) {
            try {
                (new PublicBaseReviewContext($this->client($this->responses($mode, null, $truncated))))->fetch($this->pull(), $this->preview(), 'src/Foo.php');
                self::fail('invalid tree');
            } catch (\RuntimeException) { self::assertTrue(true); }
        }
    }

    public function testBlobTamperingAndBinaryFailClosed(): void
    {
        $responses = $this->responses();
        $responses[3]['content'] = base64_encode("first\nnot\nlast\n");
        try { (new PublicBaseReviewContext($this->client($responses)))->fetch($this->pull(), $this->preview(), 'src/Foo.php'); self::fail('tampered'); } catch (\RuntimeException) { self::assertTrue(true); }
        $this->expectException(\RuntimeException::class);
        (new PublicBaseReviewContext($this->client($this->responses('100644', "binary\0old\n"))))->fetch($this->pull(), $this->preview(), 'src/Foo.php');
    }

    public function testShiftedBaseAndMalformedHunksAreRejected(): void
    {
        foreach ([
            ['content' => "extra\nfirst\nold\nlast\n", 'patch' => "@@ -2,1 +2,1 @@\n-old\n+new"],
            ['content' => "first\nold\nlast\n", 'patch' => "@@ -2,2 +2,1 @@\n-old\n+new"],
            ['content' => "first\nold\nlast\n", 'patch' => "@@ -2,1 +2,1 @@\n?invalid\n-old\n+new"],
        ] as $case) {
            $preview = $this->preview();
            $preview['files'][0]['patch'] = $case['patch'];
            try {
                (new PublicBaseReviewContext($this->client($this->responses('100644', $case['content']))))->fetch($this->pull(), $preview, 'src/Foo.php');
                self::fail('Mismatched or malformed patch accepted.');
            } catch (\RuntimeException) { self::assertTrue(true); }
        }
    }

    public function testMatchingMultipleHunksAndNoNewlineMarkers(): void
    {
        $preview = $this->preview();
        $preview['files'][0]['patch'] = "@@ -1,2 +1,2 @@\n first\n-old\n+new\n@@ -3,1 +3,1 @@\n-last\n\\ No newline at end of file\n+new-last\n\\ No newline at end of file";
        $result = (new PublicBaseReviewContext($this->client($this->responses('100644', "first\nold\nlast"))))->fetch($this->pull(), $preview, 'src/Foo.php');
        self::assertCount(2, $result['snippets']);
        self::assertSame("first\nold\nlast", $result['snippets'][0]['text']);
        $preview['files'][0]['patch'] = str_replace("\\ No newline at end of file\n+new-last", '+new-last', $preview['files'][0]['patch']);
        $this->expectException(\RuntimeException::class);
        (new PublicBaseReviewContext($this->client($this->responses('100644', "first\nold\nlast"))))->fetch($this->pull(), $preview, 'src/Foo.php');
    }

    public function testExtraContextIsDigestBoundAndChangedBytesCannotReachProvider(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls) { ++$calls; return new MockResponse(); });
        $agent = new PullRequestReview($http, new RequestBudget(sys_get_temp_dir().'/unused-'.bin2hex(random_bytes(4)), 1000000, 1));
        $context = (new PublicBaseReviewContext($this->client($this->responses())))->fetch($this->pull(), $this->preview(), 'src/Foo.php');
        $digest = $agent->preview($this->pull(), $this->preview(), $context)['digest'];
        $context['snippets'][0]['text'] = 'tampered';
        try {
            $agent->review($this->pull(), $this->preview(), $digest, 'valid-test-baseten-key-12345', false, $context);
            self::fail('Changed context was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $calls);
        }
    }

    public function testAuthenticatedContextUsesGithubWithoutLeakingCredential(): void
    {
        $token = 'ghu_test_token_123456789';
        $responses = $this->responses();
        $calls = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$calls, $token) {
            ++$calls;
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://api.github.com/repos/example/project/git/', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertContains('Authorization: Bearer '.$token, $options['headers']);
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
        $out = (new PublicBaseReviewContext($client, $token))->fetch($this->pull(), $this->preview(), 'src/Foo.php');
        self::assertSame(4, $calls);
        self::assertStringNotContainsString($token, json_encode($out, JSON_THROW_ON_ERROR));
    }

    public function testAuthenticatedContextRejectsCredentialInDecodedBlobAndSanitizesFailures(): void
    {
        $token = 'abc"defghijklmnop123';
        $responses = $this->responses('100644', "first\nold\n".$token."\n");
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$responses, &$calls) {
            ++$calls;
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
        try {
            (new PublicBaseReviewContext($client, $token))->fetch($this->pull(), $this->preview(), 'src/Foo.php');
            self::fail('A base64-encoded credential was accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertSame(4, $calls);
        }
        $calls = 0;
        $client = new MockHttpClient(static function () use ($token, &$calls) { ++$calls; throw new \RuntimeException('transport '.$token); });
        try {
            (new PublicBaseReviewContext($client, $token))->fetch($this->pull(), $this->preview(), 'src/Foo.php');
            self::fail('Transport failure was accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('Public base context request failed.', $e->getMessage());
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertSame(1, $calls);
        }
    }
}
