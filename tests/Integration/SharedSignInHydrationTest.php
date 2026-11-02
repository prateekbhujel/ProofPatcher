<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Http\Middleware\AuthorizeGithubTasks;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SharedSignInHydrationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();
    }

    protected function tearDown(): void
    {
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    /** @return list<MockResponse> */
    private function verifyResponses(int $userId, bool $grant): array
    {
        $responses = [
            new MockResponse(json_encode(['id' => $userId, 'login' => 'user' . $userId])),
            new MockResponse(json_encode(['installations' => $grant ? [['id' => 101]] : []])),
        ];
        if ($grant) {
            $responses[] = new MockResponse(json_encode(['repositories' => [[
                'id' => 5, 'name' => 'repo', 'full_name' => 'owner/repo',
                'owner' => ['login' => 'owner'], 'private' => false,
            ]]]));
        }
        return $responses;
    }

    private function request(string $method, string $path, array $sessionData = []): Request
    {
        $session = app('session')->driver('array');
        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }
        $request = Request::create($path, $method);
        $request->setLaravelSession($session);
        return $request;
    }

    public function testTerminalSignInIsAdoptedIntoEmptyWebSession(): void
    {
        GitHubTokenStore::save('file-token', ['id' => 9, 'login' => 'cli-user'], []);
        $middleware = new AuthorizeGithubTasks(new GitHubAppClient(new MockHttpClient($this->verifyResponses(9, true))));
        $request = $this->request('GET', '/issues');

        $middleware->handle($request, static fn () => new Response('ok'));

        $auth = $request->session()->get('github_auth');
        $this->assertSame('file-token', $auth['token']);
        $this->assertSame(9, $auth['user']['id']);
        $this->assertArrayHasKey('verified_at', $auth);
        $access = $request->attributes->get('proofpatch_github_access');
        $this->assertSame(9, $access['userId']);
        $this->assertArrayHasKey('owner/repo', $access['repositories']);
    }

    public function testRejectedFileTokenLeavesWebSessionDisconnected(): void
    {
        GitHubTokenStore::save('dead-token', ['id' => 9, 'login' => 'cli-user'], []);
        $middleware = new AuthorizeGithubTasks(new GitHubAppClient(new MockHttpClient([
            new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]),
        ])));
        $request = $this->request('GET', '/issues');

        $middleware->handle($request, static fn () => new Response('ok'));

        $auth = $request->session()->get('github_auth');
        $this->assertNull($auth['user']);
        $this->assertSame([], $auth['repositories']);
        $access = $request->attributes->get('proofpatch_github_access');
        $this->assertNull($access['userId']);
    }

    public function testExistingWebSessionIsNeverClobberedByFileStore(): void
    {
        GitHubTokenStore::save('file-token', ['id' => 9, 'login' => 'cli-user'], []);
        $middleware = new AuthorizeGithubTasks(new GitHubAppClient(new MockHttpClient($this->verifyResponses(3, false))));
        $request = $this->request('GET', '/issues', [
            'github_auth' => ['token' => 'session-token', 'user' => ['id' => 3]],
        ]);

        $middleware->handle($request, static fn () => new Response('ok'));

        $auth = $request->session()->get('github_auth');
        $this->assertSame('session-token', $auth['token']);
        $access = $request->attributes->get('proofpatch_github_access');
        $this->assertSame(3, $access['userId']);
    }

    public function testTerminalSelectionIsAdoptedWhenSessionHasNone(): void
    {
        KernelCli::setSelectedRepo('owner/repo');
        $middleware = new AuthorizeGithubTasks(new GitHubAppClient(new MockHttpClient($this->verifyResponses(3, true))));
        $request = $this->request('GET', '/issues', [
            'github_auth' => ['token' => 'session-token', 'user' => ['id' => 3]],
        ]);

        $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertSame('owner/repo', $request->session()->get('selected_github_repo'));
    }

    public function testSelectionOutsideGrantIsNotAdopted(): void
    {
        KernelCli::setSelectedRepo('evil/other');
        $middleware = new AuthorizeGithubTasks(new GitHubAppClient(new MockHttpClient($this->verifyResponses(3, true))));
        $request = $this->request('GET', '/issues', [
            'github_auth' => ['token' => 'session-token', 'user' => ['id' => 3]],
        ]);

        $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertNull($request->session()->get('selected_github_repo'));
    }
}
