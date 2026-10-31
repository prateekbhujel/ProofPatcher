<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Selecting a repository on the Pull Requests page must refresh BOTH lists. */
final class RepositorySelectionSyncTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        $_SESSION = [];

        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testSelectingRepoRefreshesIssuesNotJustPulls(): void
    {
        $_SESSION['github_auth'] = [
            'token' => 'mock_token',
            'repositories' => [
                ['fullName' => 'old/repo'],
                ['fullName' => 'new/repo'],
            ],
        ];
        $_SESSION['selected_github_repo'] = 'old/repo';
        $_SESSION['github_repo_issues'] = [['number' => 1, 'title' => 'Old repo issue']];
        $_SESSION['github_repo_pulls'] = [['number' => 2, 'title' => 'Old repo pull']];
        $_SESSION['pullRequest'] = ['repository' => 'old/repo', 'number' => 2];

        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/issues?') && !str_contains($url, 'page=2')) {
                return new MockResponse(json_encode([['number' => 9, 'title' => 'New repo issue']]));
            }
            if (str_contains($url, '/pulls?') && !str_contains($url, 'page=2')) {
                return new MockResponse(json_encode([[
                    'number' => 7, 'title' => 'New repo pull', 'state' => 'open',
                    'head' => ['ref' => 'fix', 'sha' => str_repeat('1', 40)],
                    'base' => ['ref' => 'main', 'sha' => str_repeat('2', 40), 'repo' => ['private' => false, 'visibility' => 'public']],
                    'user' => ['login' => 'contrib'],
                ]]));
            }

            return new MockResponse(json_encode([]));
        });
        $controller = new DashboardController(new GitHubAppClient($http));
        $request = Request::create('/github/repositories/select', 'POST', ['repository' => 'new/repo']);

        $response = $controller->selectInstalledRepository($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('new/repo', $_SESSION['selected_github_repo']);
        $this->assertSame('New repo pull', $_SESSION['github_repo_pulls'][0]['title']);
        // The bug: issues kept showing the previous repository.
        $this->assertSame('New repo issue', $_SESSION['github_repo_issues'][0]['title']);
        // Stale captured-PR case from the previous repository must go too.
        $this->assertArrayNotHasKey('pullRequest', $_SESSION);
    }

    public function testReselectingSameRepoKeepsCapturedCase(): void
    {
        $_SESSION['github_auth'] = [
            'token' => 'mock_token',
            'repositories' => [['fullName' => 'same/repo']],
        ];
        $_SESSION['selected_github_repo'] = 'same/repo';
        $_SESSION['pullRequest'] = ['repository' => 'same/repo', 'number' => 2];

        $http = new MockHttpClient([
            new MockResponse(json_encode([])),
            new MockResponse(json_encode([])),
        ]);
        $controller = new DashboardController(new GitHubAppClient($http));
        $request = Request::create('/github/repositories/select', 'POST', ['repository' => 'same/repo']);

        $controller->selectInstalledRepository($request);

        $this->assertSame('same/repo', $_SESSION['selected_github_repo']);
        $this->assertArrayHasKey('pullRequest', $_SESSION);
    }
}
