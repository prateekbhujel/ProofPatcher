<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

require_once dirname(__DIR__, 2).'/app/GitHub/DedicatedTestRepoPublisher.php';

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ObservedDraftDuplicateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        putenv('PROOFPATCHER_TEST_REPO=example/demo');
        putenv('PROOFPATCHER_TEST_BASE_COMMIT='.str_repeat('a', 40));
        $this->dir = sys_get_temp_dir().'/pp-duplicate-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir);
        putenv('PROOFPATCHER_TEST_REPO');
        putenv('PROOFPATCHER_TEST_BASE_COMMIT');
    }

    public function testMatchingSingleFileDraftIsReturnedWithoutWrites(): void
    {
        $candidate = '<?php function orderTotal(array $items): int { return 1; }';
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$calls, $candidate): MockResponse {
            $calls[] = $method;
            if (str_contains($url, '/pulls?')) return new MockResponse(json_encode([[
                'number' => 14, 'draft' => true, 'html_url' => 'https://github.com/example/demo/pull/14',
                'base' => ['ref' => 'main', 'sha' => str_repeat('a', 40)],
                'head' => ['sha' => str_repeat('b', 40), 'ref' => 'proofpatch/test', 'repo' => ['full_name' => 'example/demo']],
            ]]));
            if (str_contains($url, '/pulls/14/files')) return new MockResponse(json_encode([['filename' => 'src/OrderTotal.php', 'status' => 'modified']]));
            if (str_contains($url, '/contents/')) return new MockResponse(json_encode(['encoding' => 'base64', 'content' => base64_encode($candidate)]));
            throw new \RuntimeException('Unexpected request.');
        });
        $publisher = new DedicatedTestRepoPublisher(new ServerHeldPublicationApproval($this->dir, str_repeat('s', 32)), $http);
        $result = (new \ReflectionMethod($publisher, 'findObservedDraft'))->invoke($publisher, 'test-token', hash('sha256', $candidate));
        self::assertSame('existing', $result['status']);
        self::assertSame(14, $result['prNumber']);
        self::assertSame(['GET', 'GET', 'GET'], $calls);
    }

    public function testIncompleteDraftListingFailsClosed(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}'));
        $publisher = new DedicatedTestRepoPublisher(new ServerHeldPublicationApproval($this->dir, str_repeat('s', 32)), $http);
        $this->expectException(\RuntimeException::class);
        (new \ReflectionMethod($publisher, 'findObservedDraft'))->invoke($publisher, 'test-token', str_repeat('f', 64));
    }
}
