<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\ManualInlineCommentPublisher;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Http\Middleware\AuthorizeGithubTasks;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PrivateTestPullSessionTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string|false $previousRepo;
    private string|false $previousSnapshotDir;
    private string $directory;
    private int $githubUser = 1;
    private bool $grant = true;
    private int $requests = 0;
    private int $comments = 0;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        $this->previousRepo = getenv('PROOFPATCH_TEST_REPO');
        $this->previousSnapshotDir = getenv('PROOFPATCH_SNAPSHOT_DIR');
        $this->directory = sys_get_temp_dir().'/private-pr-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        putenv('PROOFPATCH_TEST_REPO=owner/private-test');
        putenv('PROOFPATCH_SNAPSHOT_DIR='.$this->directory);
        config(['session.driver' => 'array']);
    }

    protected function tearDown(): void
    {
        $this->previousRepo === false ? putenv('PROOFPATCH_TEST_REPO') : putenv('PROOFPATCH_TEST_REPO='.$this->previousRepo);
        $this->previousSnapshotDir === false ? putenv('PROOFPATCH_SNAPSHOT_DIR') : putenv('PROOFPATCH_SNAPSHOT_DIR='.$this->previousSnapshotDir);
        foreach (glob($this->directory.'/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function client(): GitHubAppClient
    {
        return new GitHubAppClient($this->http());
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            ++$this->requests;
            if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === '/repos/owner/private-test/pulls/3/comments') {
                ++$this->comments;
                $body = json_decode($options['body'], true, 16, JSON_THROW_ON_ERROR);
                self::assertSame('local draft', $body['body']);
                self::assertSame(str_repeat('a', 40), $body['commit_id']);
                return new MockResponse(json_encode(['id' => 123]), ['http_code' => 201]);
            }
            self::assertSame('GET', $method);
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/user') return new MockResponse(json_encode(['id' => $this->githubUser, 'login' => 'owner']));
            if ($path === '/user/installations') return new MockResponse(json_encode(['installations' => $this->grant ? [['id' => 8]] : []]));
            if ($path === '/user/installations/8/repositories') return new MockResponse(json_encode(['repositories' => [[
                'id' => 7, 'name' => 'private-test', 'full_name' => 'owner/private-test',
                'owner' => ['login' => 'owner'], 'private' => true,
            ]]]));
            if ($path === '/repos/owner/private-test/pulls/3') return new MockResponse(json_encode([
                'number' => 3, 'title' => 'Private example', 'state' => 'open', 'changed_files' => 1,
                'head' => ['sha' => str_repeat('a', 40), 'ref' => 'fix'],
                'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main', 'repo' => ['private' => true]],
                'user' => ['login' => 'owner'],
            ]));
            if ($path === '/repos/owner/private-test/pulls/3/files') return new MockResponse(json_encode([[
                'filename' => 'src/example.php', 'patch' => "@@ -1,2 +1,3 @@\n one\n two\n+private-example",
            ]]));
            throw new \RuntimeException('Unexpected outbound request');
        });
    }

    private function request(string $method, string $path, array $data, $session): Request
    {
        $request = Request::create($path, $method, $data);
        $request->setLaravelSession($session);
        app('redirect')->setSession($session);
        return $request;
    }

    private function capture($session): void
    {
        $session->put('github_auth', ['token' => 'mock-token', 'user' => ['id' => 1]]);
        $request = $this->request('POST', '/github/pulls/capture', ['repository' => 'owner/private-test', 'number' => 3], $session);
        $client = $this->client();
        $response = (new AuthorizeGithubTasks($client))->handle($request, fn (Request $request) => (new DashboardController($client))->captureInstalledPullRequest($request));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('owner/private-test', $session->get('pullRequest')['repository'] ?? null);
    }

    public function testOwnedGrantedPrivateTestPullStaysInSessionAndCannotReachModel(): void
    {
        $session = app('session')->driver('array');
        $this->capture($session);
        self::assertSame(1, $session->get('private_pull_owner'));
        self::assertTrue($session->get('pullRequest')['isPrivate']);
        self::assertStringContainsString('private-example', $session->get('patch_preview')['files'][0]['patch']);
        self::assertNull($session->get('pullRequestSnapshot'));
        self::assertSame([], glob($this->directory.'/*'));

        $request = $this->request('GET', '/repositories?preview=1', [], $session);
        $client = $this->client();
        (new AuthorizeGithubTasks($client))->handle($request, fn () => new Response());
        $response = (new DashboardController($client))->repositories($request);
        $props = $response->toResponse($request)->getOriginalContent()->getData()['page']['props'];
        self::assertTrue($props['pull']['isPrivate']);
        self::assertStringContainsString('private-example', $props['patchPreview']['files'][0]['patch']);
        self::assertNull($props['reviewPreview']);
        self::assertNull($session->get('review_approval_token'));

        $before = $this->requests;
        $review = $this->request('POST', '/repositories/review', ['confirm' => true], $session);
        (new DashboardController($client))->reviewPullRequest($review);
        self::assertSame($before, $this->requests);
        self::assertSame([], glob($this->directory.'/*'));
    }

    public function testInspectFormUsesAuthenticatedCaptureForTheDedicatedPrivateRepo(): void
    {
        foreach (['owner/private-test#3', 'https://github.com/owner/private-test/pull/3'] as $target) {
            $session = app('session')->driver('array');
            $session->flush();
            $session->put('github_auth', ['token' => 'mock-token', 'user' => ['id' => 1]]);
            $request = $this->request('POST', '/repositories', ['url' => $target], $session);
            $client = $this->client();
            (new AuthorizeGithubTasks($client))->handle($request, fn (Request $request) => (new DashboardController($client))->repositories($request));
            self::assertSame('owner/private-test', $session->get('pullRequest')['repository'] ?? null);
            self::assertNull($session->get('pullRequestSnapshot'));
            self::assertSame([], glob($this->directory.'/*'));
        }
    }

    public function testInspectFormDoesNotCapturePrivateRepoWithoutGrant(): void
    {
        $this->grant = false;
        $session = app('session')->driver('array');
        $session->put('github_auth', ['token' => 'mock-token', 'user' => ['id' => 1]]);
        $request = $this->request('POST', '/repositories', ['url' => 'owner/private-test#3'], $session);
        $client = $this->client();
        (new AuthorizeGithubTasks($client))->handle($request, fn (Request $request) => (new DashboardController($client))->repositories($request));
        self::assertNull($session->get('pullRequest'));
        self::assertSame([], glob($this->directory.'/*'));
    }

    public function testReviewPageReportsKeyAvailabilityWithoutExposingTheKey(): void
    {
        $previous = getenv('BASETEN_API_KEY');
        try {
            foreach (['short-key-123' => false, 'unit-test-key-123456789' => true] as $key => $expected) {
                putenv('BASETEN_API_KEY='.$key);
                $session = app('session')->driver('array');
                $request = $this->request('GET', '/repositories', [], $session);
                $response = (new DashboardController($this->client()))->repositories($request);
                $props = $response->toResponse($request)->getOriginalContent()->getData()['page']['props'];
                self::assertSame($expected, $props['modelKeyAvailable']);
                self::assertStringNotContainsString($key, json_encode($props, JSON_THROW_ON_ERROR));
            }
        } finally {
            $previous === false ? putenv('BASETEN_API_KEY') : putenv('BASETEN_API_KEY='.$previous);
        }
    }

    public function testRevocationAndAccountChangeErasePrivateSessionOnNextRequest(): void
    {
        foreach (['revoke', 'switch'] as $mode) {
            $this->githubUser = 1;
            $this->grant = true;
            $session = app('session')->driver('array');
            $session->flush();
            $this->capture($session);
            $session->put('review_drafts', ['d' => ['comment' => 'private draft']]);
            if ($mode === 'revoke') $this->grant = false;
            else $this->githubUser = 2;
            $request = $this->request('GET', '/repositories', [], $session);
            (new AuthorizeGithubTasks($this->client()))->handle($request, fn () => new Response());
            foreach (['private_pull_owner', 'pullRequest', 'pullRequestSnapshot', 'patch_preview', 'review_drafts'] as $key) {
                self::assertNull($session->get($key));
            }
        }
    }

    public function testPrivateCaptureRejectsUnverifiedOrClientSideSession(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_auth', ['token' => 'mock-token', 'user' => ['id' => 1], 'repositories' => [[
            'fullName' => 'owner/private-test', 'isPrivate' => true,
        ]]]);
        $request = $this->request('POST', '/github/pulls/capture', ['repository' => 'owner/private-test', 'number' => 3], $session);
        (new DashboardController($this->client()))->captureInstalledPullRequest($request);
        self::assertNull($session->get('pullRequest'));
        self::assertSame(0, $this->requests);

        $request->attributes->set('proofpatch_github_access', ['userId' => 1, 'repositories' => ['owner/private-test' => true]]);
        config(['session.driver' => 'cookie']);
        (new DashboardController($this->client()))->captureInstalledPullRequest($request);
        self::assertNull($session->get('pullRequest'));
        self::assertSame(0, $this->requests);
        self::assertSame([], glob($this->directory.'/*'));
    }

    public function testKernelSessionLifecycleClearsPrivatePullOnDisconnectOrRevokedGrant(): void
    {
        $client = $this->client();
        app()->instance(GitHubAppClient::class, $client);
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        // Start a real Laravel session and then seed a mocked, authenticated GitHub identity.
        $start = Request::create('/repositories', 'GET');
        $startResponse = $kernel->handle($start);
        self::assertSame(200, $startResponse->getStatusCode());
        $cookies = [];
        foreach ($startResponse->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();
        self::assertArrayHasKey('proofpatch_session', $cookies);
        $session = $start->session();
        $session->put('github_auth', ['token' => 'mock-token', 'user' => ['id' => 1]]);
        $session->save();
        $kernel->terminate($start, $startResponse);

        $capture = Request::create('/github/pulls/capture', 'POST', ['repository' => 'owner/private-test', 'number' => 3], $cookies);
        $captured = $kernel->handle($capture);
        self::assertSame(302, $captured->getStatusCode());
        self::assertTrue($capture->session()->get('pullRequest')['isPrivate']);
        $kernel->terminate($capture, $captured);

        $saveDraft = Request::create('/repositories/review/draft', 'POST', [
            'path' => 'src/example.php', 'line' => 3, 'side' => 'RIGHT',
            'headSha' => str_repeat('a', 40), 'comment' => 'local draft',
        ], $cookies);
        $saved = $kernel->handle($saveDraft);
        self::assertSame(302, $saved->getStatusCode());
        $drafts = $saveDraft->session()->get('review_drafts');
        self::assertCount(1, $drafts);
        $draft = array_values($drafts)[0];
        self::assertTrue($draft['commentable']);
        $kernel->terminate($saveDraft, $saved);

        $preview = Request::create('/repositories?preview=1', 'GET', [], $cookies, [], [
            'HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INERTIA_VERSION' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);
        $previewResponse = $kernel->handle($preview);
        self::assertSame(200, $previewResponse->getStatusCode(), substr((string) $previewResponse->getContent(), 0, 300));
        self::assertStringContainsString('no-store', (string) $previewResponse->headers->get('Cache-Control'));
        self::assertStringContainsString('json', (string) $previewResponse->headers->get('Content-Type'), substr((string) $previewResponse->getContent(), 0, 300));
        $page = json_decode((string) $previewResponse->getContent(), true, 64, JSON_THROW_ON_ERROR);
        self::assertNull($page['props']['reviewPreview']);
        self::assertArrayHasKey($draft['id'], $page['props']['commentApprovals']);
        $digest = $page['props']['commentApprovals'][$draft['id']];
        $kernel->terminate($preview, $previewResponse);

        app()->instance(ManualInlineCommentPublisher::class, new ManualInlineCommentPublisher($this->http()));
        $publish = Request::create('/repositories/review/comment', 'POST', ['id' => $draft['id'], 'digest' => $digest, 'approved' => true], $cookies);
        $published = $kernel->handle($publish);
        self::assertSame(302, $published->getStatusCode());
        self::assertSame(1, $this->comments);
        self::assertSame([], $publish->session()->get('review_drafts'));
        $kernel->terminate($publish, $published);

        $disconnect = Request::create('/github/device/disconnect', 'POST', [], $cookies);
        $disconnected = $kernel->handle($disconnect);
        self::assertSame(302, $disconnected->getStatusCode());
        self::assertNull($disconnect->session()->get('github_auth'));
        self::assertNull($disconnect->session()->get('pullRequest'), 'Disconnect redirect: '.$disconnected->headers->get('Location'));
        self::assertNull($disconnect->session()->get('patch_preview'));
        $kernel->terminate($disconnect, $disconnected);

        // A fresh capture is also cleared by the real middleware when its grant disappears.
        $seed = Request::create('/repositories', 'GET', [], $cookies);
        $seedResponse = $kernel->handle($seed);
        $seed->session()->put('github_auth', ['token' => 'mock-token', 'user' => ['id' => 1]]);
        $seed->session()->save();
        $kernel->terminate($seed, $seedResponse);
        $captureAgain = Request::create('/github/pulls/capture', 'POST', ['repository' => 'owner/private-test', 'number' => 3], $cookies);
        $capturedAgain = $kernel->handle($captureAgain);
        self::assertTrue($captureAgain->session()->get('pullRequest')['isPrivate']);
        $kernel->terminate($captureAgain, $capturedAgain);
        $this->grant = false;
        $revoked = Request::create('/repositories', 'GET', [], $cookies);
        $revokedResponse = $kernel->handle($revoked);
        self::assertSame(200, $revokedResponse->getStatusCode());
        self::assertNull($revoked->session()->get('pullRequest'));
        self::assertStringNotContainsString('private-example', (string) $revokedResponse->getContent());
        $kernel->terminate($revoked, $revokedResponse);

        $this->grant = true;
        $this->githubUser = 1;
        $seedAgain = Request::create('/repositories', 'GET', [], $cookies);
        $seedAgainResponse = $kernel->handle($seedAgain);
        $seedAgain->session()->put('github_auth', ['token' => 'mock-token', 'user' => ['id' => 1]]);
        $seedAgain->session()->save();
        $kernel->terminate($seedAgain, $seedAgainResponse);
        $lastCapture = Request::create('/github/pulls/capture', 'POST', ['repository' => 'owner/private-test', 'number' => 3], $cookies);
        $lastCaptureResponse = $kernel->handle($lastCapture);
        self::assertTrue($lastCapture->session()->get('pullRequest')['isPrivate']);
        $kernel->terminate($lastCapture, $lastCaptureResponse);
        $this->githubUser = 2;
        $switched = Request::create('/repositories', 'GET', [], $cookies);
        $switchedResponse = $kernel->handle($switched);
        self::assertSame(200, $switchedResponse->getStatusCode());
        self::assertNull($switched->session()->get('pullRequest'));
        self::assertStringNotContainsString('private-example', (string) $switchedResponse->getContent());
        $kernel->terminate($switched, $switchedResponse);
        self::assertSame([], glob($this->directory.'/*'));
    }
}
