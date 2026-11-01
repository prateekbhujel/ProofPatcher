<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoFetcher;
use ProofPatch\Http\Controllers\IssueWorkbenchController;
use ProofPatch\Models\WorkbenchTask;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PrivateMuseConsentTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $dir;
    private string|false $previousKey;
    private int $providerCalls = 0;
    private string $issueBody = 'The total is wrong when quantity is zero.';

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        config(['session.driver' => 'array']);
        $this->dir = sys_get_temp_dir().'/private-muse-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->previousKey = getenv('BASETEN_API_KEY');
        putenv('BASETEN_API_KEY=unit-test-baseten-key-123456');
    }

    protected function tearDown(): void
    {
        $this->previousKey === false ? putenv('BASETEN_API_KEY') : putenv('BASETEN_API_KEY='.$this->previousKey);
        $this->teardownIsolatedDatabase();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        @rmdir($this->dir);
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function context(): array
    {
        $source = file_get_contents(base_path('tests/Fixtures/owned-review-case/src/OrderTotal.php'));
        $test = file_get_contents(base_path('tests/Fixtures/owned-review-case/tests/order_total.php'));
        return ['repository' => DedicatedTestRepo::requireName(), 'issueNumber' => 1,
            'issueUrl' => 'https://github.com/'.DedicatedTestRepo::requireName().'/issues/1',
            'commit' => DedicatedTestRepo::requireBaseCommit(), 'title' => 'Zero-quantity order bug',
            'body' => $this->issueBody, 'path' => 'src/OrderTotal.php', 'source' => $source,
            'sourceSha256' => hash('sha256', $source), 'testSource' => $test, 'testSha256' => hash('sha256', $test)];
    }

    private function controller(): IssueWorkbenchController
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $ctx = $this->context();
            if ($method === 'GET' && str_ends_with($url, '/issues/1')) {
                return new MockResponse(json_encode(['number' => 1, 'state' => 'open', 'title' => $ctx['title'], 'body' => $ctx['body']]));
            }
            if ($method === 'GET' && str_contains($url, '/contents/src/OrderTotal.php')) return new MockResponse(json_encode(['content' => base64_encode($ctx['source'])]));
            if ($method === 'GET' && str_contains($url, '/contents/tests/order_total.php')) return new MockResponse(json_encode(['content' => base64_encode($ctx['testSource'])]));
            if ($method === 'POST' && str_starts_with($url, 'https://inference.baseten.co/')) {
                $this->providerCalls++;
                $payload = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
                $sentContext = json_decode($payload['messages'][1]['content'], true, 32, JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('testSource', $sentContext);
                self::assertArrayNotHasKey('testSha256', $sentContext);
                self::assertSame($ctx['source'], $sentContext['source']);
                self::assertSame($ctx['body'], $sentContext['body']);
                $content = json_encode(['path' => $ctx['path'], 'find' => "max(1, \$item['quantity'])", 'replace' => "\$item['quantity']", 'explanation' => 'Use actual quantity.']);
                return new MockResponse(json_encode(['model' => 'meta-models/muse-spark-open-1026',
                    'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30]]));
            }
            throw new \RuntimeException('Unexpected network request: '.$method.' '.$url);
        });
        return new IssueWorkbenchController($http, new RequestBudget($this->dir.'/budget', 500000, 10), null, new SingleUseReviewApproval($this->dir.'/approvals'));
    }

    private function setupCase(): array
    {
        $ctx = $this->context();
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'mock-github-token', 'user' => ['id' => 7], 'repositories' => [
            ['fullName' => $ctx['repository'], 'isPrivate' => true],
        ]]);
        $task = WorkbenchTask::recordIntake($ctx, true, githubUserId: 7);
        $session->put('issue_task_id', $task->id);
        $session->put('issue_context', $ctx);
        $session->put('issue_is_dedicated_test_repo', true);
        $session->put('issue_is_owned_fixture', true);
        $access = ['userId' => 7, 'repositories' => [strtolower($ctx['repository']) => true]];
        $controller = $this->controller();
        $show = Request::create('/issues', 'GET');
        $show->setLaravelSession($session);
        $show->attributes->set('proofpatch_github_access', $access);
        $props = $controller->show($show)->toResponse($show)->getOriginalContent()->getData()['page']['props'];
        self::assertTrue($props['privateModelConsentRequired']);
        self::assertTrue($props['modelContextAllowed']);
        return [$controller, $session, $access, $props['preview']];
    }

    private function request($session, array $access, array $preview, bool $consent): Request
    {
        $request = Request::create('/issues/propose', 'POST', ['digest' => $preview['digest'], 'approved' => true,
            'approval_token' => $preview['approvalToken'], 'private_consent' => $consent]);
        $request->setLaravelSession($session);
        $request->attributes->set('proofpatch_github_access', $access);
        return $request;
    }

    public function testPrivateContextRequiresConsentAndSingleUseThenReturnsUnverifiedSuggestion(): void
    {
        [$controller, $session, $access, $preview] = $this->setupCase();
        $controller->propose($this->request($session, $access, $preview, false));
        self::assertSame(0, $this->providerCalls);
        $controller->propose($this->request($session, $access, $preview, true));
        self::assertSame(1, $this->providerCalls, (string) $session->get('error'));
        self::assertFalse($session->get('issue_suggestion')['candidate']['verified']);
        $controller->propose($this->request($session, $access, $preview, true));
        self::assertSame(1, $this->providerCalls);
    }

    public function testRevocationAndIssueDriftPreventProviderRequest(): void
    {
        [$controller, $session, $access, $preview] = $this->setupCase();
        $access['repositories'] = [];
        $controller->propose($this->request($session, $access, $preview, true));
        self::assertSame(0, $this->providerCalls);
        $access['repositories'] = [strtolower($this->context()['repository']) => true];
        $this->issueBody = 'Changed issue description.';
        $controller->propose($this->request($session, $access, $preview, true));
        self::assertSame(0, $this->providerCalls);
        self::assertStringContainsString('changed', (string) $session->get('error'));
    }

    public function testAccountSwitchCannotUseAnotherUsersPrivateApproval(): void
    {
        [$controller, $session, $access, $preview] = $this->setupCase();
        $access['userId'] = 8;
        $controller->propose($this->request($session, $access, $preview, true));
        self::assertSame(0, $this->providerCalls);
        self::assertNull($session->get('issue_suggestion'));
    }

    public function testClientSideSessionCannotAuthorizePrivateProviderRequest(): void
    {
        [$controller, $session, $access, $preview] = $this->setupCase();
        config(['session.driver' => 'cookie']);
        $controller->propose($this->request($session, $access, $preview, true));
        self::assertSame(0, $this->providerCalls);
        self::assertNull($session->get('issue_suggestion'));
    }
}
