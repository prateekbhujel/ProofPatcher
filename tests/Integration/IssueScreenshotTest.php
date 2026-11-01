<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Http\Controllers\IssueScreenshotController;
use ProofPatch\Http\Middleware\AuthorizeGithubTasks;
use ProofPatch\Models\WorkbenchTask;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IssueScreenshotTest extends TestCase
{
    use InteractsWithIsolatedDatabase;
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) @unlink($path);
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function task(?int $userId = 7, bool $synthetic = false, int $issueNumber = 1): WorkbenchTask
    {
        $context = ['repository' => 'owner/private-repo', 'issueNumber' => $issueNumber, 'path' => 'src/OrderTotal.php',
            'isSynthetic' => $synthetic, 'sourceSha256' => hash('sha256', 'v1')];
        return WorkbenchTask::recordIntake($context, false, null, $userId);
    }

    private function middleware(int $user, bool $grant): AuthorizeGithubTasks
    {
        $responses = [
            new MockResponse(json_encode(['id' => $user, 'login' => 'user' . $user])),
            new MockResponse(json_encode(['installations' => $grant ? [['id' => 101]] : []])),
        ];
        if ($grant) {
            $responses[] = new MockResponse(json_encode(['repositories' => [[
                'id' => 5, 'name' => 'private-repo', 'full_name' => 'owner/private-repo',
                'owner' => ['login' => 'owner'], 'private' => true,
            ]]]));
        }
        return new AuthorizeGithubTasks(new GitHubAppClient(new MockHttpClient($responses)));
    }

    private function request(string $method, ?int $taskId, ?int $user, ?UploadedFile $image = null): Request
    {
        $session = app('session')->driver('array');
        if ($taskId !== null) $session->put('issue_task_id', $taskId);
        if ($user !== null) $session->put('github_auth', ['token' => 'fake-token', 'user' => ['id' => $user]]);
        app('redirect')->setSession($session);
        $request = Request::create('/issues/screenshot', $method, [], [], $image ? ['image' => $image] : []);
        $request->setLaravelSession($session);
        return $request;
    }

    private function upload(string $bytes, string $name = 'screenshot.png', string $mime = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pp-shot-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $bytes);
        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 255, 0));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    public function testOwnerCanAttachAndViewScreenshot(): void
    {
        $task = $this->task();
        $bytes = $this->png();
        $request = $this->request('POST', $task->id, 7, $this->upload($bytes));
        $this->middleware(7, true)->handle($request, fn (Request $r) => (new IssueScreenshotController())->store($r));
        self::assertSame('Screenshot saved.', $request->session()->get('success'));
        $task->refresh();
        self::assertSame($bytes, $task->image_bytes);
        self::assertSame('image/png', $task->image_mime);

        $get = $this->request('GET', $task->id, 7);
        $response = $this->middleware(7, true)->handle($get, fn (Request $r) => (new IssueScreenshotController())->show($r));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($bytes, $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));

        $again = $this->request('POST', $task->id, 7, $this->upload($bytes));
        $this->middleware(7, true)->handle($again, fn (Request $r) => (new IssueScreenshotController())->store($r));
        self::assertSame('This task already has an image.', $again->session()->get('error'));
    }

    public function testOtherUsersGuestsAndBadFilesAreRefused(): void
    {
        $task = $this->task();
        $bytes = $this->png();

        $other = $this->request('POST', $task->id, 8, $this->upload($bytes));
        $this->middleware(8, true)->handle($other, fn (Request $r) => (new IssueScreenshotController())->store($r));
        self::assertSame('Connect the GitHub account that owns this task and grant access to its repository.', $other->session()->get('error'));

        // The controller rechecks ownership itself when reached directly.
        $direct = $this->request('POST', $task->id, 8, $this->upload($bytes));
        $direct->attributes->set('proofpatch_github_access', ['userId' => 8, 'repositories' => ['owner/private-repo' => true]]);
        (new IssueScreenshotController())->store($direct);
        self::assertSame('Task not found or access is unavailable.', $direct->session()->get('error'));

        $get = $this->request('GET', $task->id, 8);
        $response = $this->middleware(8, true)->handle($get, fn (Request $r) => (new IssueScreenshotController())->show($r));
        self::assertSame(404, $response->getStatusCode());

        $noTask = $this->request('POST', null, 7, $this->upload($bytes));
        $this->middleware(7, true)->handle($noTask, fn (Request $r) => (new IssueScreenshotController())->store($r));
        self::assertSame('Task not found or access is unavailable.', $noTask->session()->get('error'));

        $text = $this->request('POST', $task->id, 7, $this->upload('not an image', 'evil.txt', 'text/plain'));
        $this->middleware(7, true)->handle($text, fn (Request $r) => (new IssueScreenshotController())->store($r));
        self::assertSame('Choose a valid PNG or JPEG image.', $text->session()->get('error'));
    }

    public function testDisconnectedSyntheticTaskAllowsLocalScreenshotButDedicatedDoesNot(): void
    {
        $synthetic = $this->task(null, true);
        $bytes = $this->png();
        $request = $this->request('POST', $synthetic->id, null, $this->upload($bytes));
        (new IssueScreenshotController())->store($request);
        self::assertSame('Screenshot saved.', $request->session()->get('success'));

        $dedicated = $this->task(null, false, 2);
        $denied = $this->request('POST', $dedicated->id, null, $this->upload($bytes));
        (new IssueScreenshotController())->store($denied);
        self::assertSame('Task not found or access is unavailable.', $denied->session()->get('error'));
    }

    public function testScreenshotBytesNeverEnterModelRequests(): void
    {
        $task = $this->task();
        $bytes = $this->png();
        $request = $this->request('POST', $task->id, 7, $this->upload($bytes));
        $this->middleware(7, true)->handle($request, fn (Request $r) => (new IssueScreenshotController())->store($r));
        self::assertSame('Screenshot saved.', $request->session()->get('success'));

        $context = ['repository' => 'owner/private-repo', 'issueNumber' => 1,
            'issueUrl' => 'https://github.com/owner/private-repo/issues/1', 'title' => 'Bug',
            'body' => 'Broken.', 'commit' => str_repeat('a', 40), 'path' => 'src/OrderTotal.php',
            'source' => '<?php // v1', 'sourceSha256' => hash('sha256', '<?php // v1')];
        $http = new MockHttpClient(fn(): MockResponse => throw new \RuntimeException('No network may happen.'));
        $budgetDir = sys_get_temp_dir() . '/shot-budget-' . bin2hex(random_bytes(4));
        $payload = (new IssueSuggestion($http, new RequestBudget($budgetDir, 500000, 10)))->preview($context);
        $encoded = json_encode($payload);
        self::assertStringNotContainsString('image_bytes', (string) $encoded);
        self::assertStringNotContainsString(base64_encode($bytes), (string) $encoded);
        foreach (glob($budgetDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($budgetDir);
    }
}
