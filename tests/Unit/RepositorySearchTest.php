<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use ProofPatch\Http\Controllers\RepositorySearchController;
use ProofPatch\Support\RepositorySearch;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class RepositorySearchTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function repos(): array
    {
        return [
            ['fullName' => 'acme/api', 'isPrivate' => false],
            ['fullName' => 'acme/web', 'isPrivate' => true],
            ['fullName' => 'acme/worker', 'isPrivate' => false],
        ];
    }

    public function testSearchMatchesCaseInsensitivelyAndPreservesOrder(): void
    {
        $result = RepositorySearch::search($this->repos(), 'ACME/W', 1, 20);

        $this->assertSame(['acme/web', 'acme/worker'], array_column($result['data'], 'fullName'));
        $this->assertSame(2, $result['total']);
        $this->assertFalse($result['hasMore']);
    }

    public function testSearchPaginatesAndReportsMore(): void
    {
        $result = RepositorySearch::search($this->repos(), '', 2, 2);

        $this->assertSame(['acme/worker'], array_column($result['data'], 'fullName'));
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertFalse($result['hasMore']);

        $first = RepositorySearch::search($this->repos(), '', 1, 2);
        $this->assertTrue($first['hasMore']);
    }

    public function testSearchClampsBoundsAndSkipsMalformedEntries(): void
    {
        $repos = [
            ['fullName' => 'acme/ok'],
            ['fullName' => ''],
            ['name' => 'no-fullname'],
            'not-an-array',
        ];

        $result = RepositorySearch::search($repos, '', 0, 999);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(RepositorySearch::MAX_PER_PAGE, $result['perPage']);
        // Unknown privacy reads as private: the safe direction.
        $this->assertTrue($result['data'][0]['isPrivate']);
    }

    private function request(array $query, ?int $userId, array $sessionRepos): Request
    {
        $request = Request::create('/github/repositories/search', 'GET', $query);
        $store = new Store('test', new ArraySessionHandler(10));
        $store->put('github_auth', ['token' => 'test-token', 'repositories' => $sessionRepos]);
        $request->setLaravelSession($store);
        $request->attributes->set('proofpatch_github_access', [
            'userId' => $userId,
            'repositories' => ['acme/api' => true],
        ]);

        return $request;
    }

    public function testControllerRejectsUnauthenticatedSearch(): void
    {
        $response = (new RepositorySearchController())->__invoke($this->request([], null, $this->repos()));

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Connect GitHub', $payload['error']);
    }

    public function testControllerSearchesOnlyTheSessionList(): void
    {
        $response = (new RepositorySearchController())->__invoke($this->request(['q' => 'web'], 42, $this->repos()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame([['fullName' => 'acme/web', 'isPrivate' => true]], $payload['data']);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertFalse($payload['meta']['hasMore']);
    }

    public function testControllerIgnoresNonNumericPaging(): void
    {
        $response = (new RepositorySearchController())->__invoke(
            $this->request(['page' => '0;drop', 'per_page' => 'huge'], 42, $this->repos())
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(1, $payload['meta']['page']);
        $this->assertSame(RepositorySearch::DEFAULT_PER_PAGE, $payload['meta']['perPage']);
        $this->assertSame(3, $payload['meta']['total']);
    }
}
