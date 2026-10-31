<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use ProofPatch\Http\Controllers\OsStatusController;
use ProofPatch\Http\Controllers\TerminalController;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class OsShellControllerTest extends TestCase
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

    private function request(string $uri, string $method, array $input = [], array $session = []): Request
    {
        $request = Request::create($uri, $method, $input);
        $store = new Store('test', new ArraySessionHandler(10));
        foreach ($session as $key => $value) {
            $store->put($key, $value);
        }
        $request->setLaravelSession($store);

        return $request;
    }

    public function testTerminalRunAnswersHelp(): void
    {
        $response = (new TerminalController())->run($this->request('/terminal/run', 'POST', ['command' => 'help']));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(0, $payload['exit']);
        $this->assertStringContainsString('read-only', $payload['output']);
    }

    public function testTerminalRunRejectsDangerousInputWithOutputNotHttpErrors(): void
    {
        foreach (['kernel:autopilot', 'status; rm -rf /', 'reviews --limit 99'] as $command) {
            $response = (new TerminalController())->run($this->request('/terminal/run', 'POST', ['command' => $command]));

            $this->assertSame(200, $response->getStatusCode(), $command);
            $payload = json_decode((string) $response->getContent(), true);
            $this->assertSame(1, $payload['exit'], $command);
            $this->assertNotSame('', trim($payload['output']), $command);
        }
    }

    public function testTerminalRunExecutesStatus(): void
    {
        $response = (new TerminalController())->run($this->request('/terminal/run', 'POST', ['command' => 'status']));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertSame(0, $payload['exit']);
        $this->assertStringContainsString('ProofPatcher Kernel', $payload['output']);
        $this->assertStringContainsString('not connected', $payload['output']);
    }

    public function testOsStatusReportsDisconnectedStateWithConnectNext(): void
    {
        $response = (new OsStatusController())->show($this->request('/os/status', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertFalse($payload['connected']);
        $this->assertSame(0, $payload['reposCount']);
        $this->assertFalse($payload['workerLive']);
        $this->assertSame('offline', $payload['workerStatus']);
        $this->assertSame('Connect GitHub', $payload['next']['title']);
        $this->assertSame('/issues', $payload['next']['href']);
    }

    public function testEmbedFlagIsSharedFromQuery(): void
    {
        $middleware = new \ProofPatch\Http\Middleware\HandleInertiaRequests();

        $embedded = $this->request('/?embed=1', 'GET');
        $this->assertTrue($middleware->share($embedded)['embed']);

        $plain = $this->request('/', 'GET');
        $this->assertFalse($middleware->share($plain)['embed']);
    }

    public function testOsStatusReportsSessionState(): void
    {
        $request = $this->request('/os/status', 'GET', [], [
            'github_auth' => [
                'token' => 'session-token',
                'user' => ['login' => 'maintainer'],
                'repositories' => [['fullName' => 'acme/repo']],
            ],
            'selected_github_repo' => 'acme/repo',
            'github_repo_issues' => [['number' => 1], ['number' => 2]],
            'github_repo_pulls' => [['number' => 3]],
        ]);

        $payload = json_decode((string) (new OsStatusController())->show($request)->getContent(), true);

        $this->assertTrue($payload['connected']);
        $this->assertSame('maintainer', $payload['userLogin']);
        $this->assertSame(1, $payload['reposCount']);
        $this->assertSame('acme/repo', $payload['selectedRepo']);
        $this->assertSame(2, $payload['openIssues']);
        $this->assertSame(1, $payload['openPulls']);
        $this->assertSame('Open the workbench', $payload['next']['cta']);
        $this->assertArrayNotHasKey('token', $payload);
    }
}
