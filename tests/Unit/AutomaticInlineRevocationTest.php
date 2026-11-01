<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Http\Middleware\AuthorizeGithubTasks;
use ProofPatch\Models\AutomaticInlineSetting;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AutomaticInlineRevocationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void { $this->setupIsolatedDatabase(); $this->bootstrapAndMigrateIsolatedDatabase(); }
    protected function tearDown(): void { $this->teardownIsolatedDatabase(); @restore_error_handler(); @restore_exception_handler(); }

    private function verifyGrant($session, int $userId, bool $grant): void
    {
        $client = new GitHubAppClient(new MockHttpClient(static function (string $method, string $url) use ($userId, $grant): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/user') return new MockResponse(json_encode(['id' => $userId, 'login' => 'owner']));
            if ($path === '/user/installations') return new MockResponse(json_encode(['installations' => $grant ? [['id' => 8]] : []]));
            if ($path === '/user/installations/8/repositories') return new MockResponse(json_encode(['repositories' => [[
                'id' => 7, 'name' => 'repo', 'full_name' => 'owner/repo', 'owner' => ['login' => 'owner'], 'private' => false,
            ]]]));
            throw new \RuntimeException('Unexpected GitHub request');
        }));
        $request = Request::create('/settings', 'GET');
        $request->setLaravelSession($session);
        (new AuthorizeGithubTasks($client))->handle($request, fn () => new Response());
    }

    public function testObservedLossAndSubsequentRegrantRequireNewOptIn(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_auth', ['token' => 'mock', 'user' => ['id' => 7]]);
        AutomaticInlineSetting::setForOwner(7, 'owner/repo', true);
        $this->verifyGrant($session, 7, false);
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
        $this->verifyGrant($session, 7, true);
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
    }

    public function testAccountSwitchRevokesOldOwnersOptIns(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_auth', ['token' => 'mock', 'user' => ['id' => 7]]);
        AutomaticInlineSetting::setForOwner(7, 'owner/repo', true);
        $this->verifyGrant($session, 8, true);
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
        self::assertFalse(AutomaticInlineSetting::enabled(8, 'owner/repo'));
    }
}
