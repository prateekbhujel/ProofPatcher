<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\Agent\PrivateProposalStore;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\ServerHeldModelProposal;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\Application\Workbench\ProposeSuggestionInput;
use ProofPatch\Application\Workbench\ProposeSuggestionUseCase;
use ProofPatch\GitHub\DedicatedTestRepo;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The propose use case runs without HTTP or session access: plain data in,
 * result out, with the same guards and messages the controller had.
 */
final class ProposeSuggestionUseCaseTest extends TestCase
{
    private string $dir;
    private string|false $previousKey;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/propose-usecase-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->previousKey = getenv('BASETEN_API_KEY');
        putenv('BASETEN_API_KEY=unit-test-baseten-key-123456');
    }

    protected function tearDown(): void
    {
        $this->previousKey === false ? putenv('BASETEN_API_KEY') : putenv('BASETEN_API_KEY=' . $this->previousKey);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return array{0: array<string,mixed>, 1: string, 2: string} context, source, harness */
    private function dedicatedContext(): array
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');
        $test = (string) file_get_contents($root . '/tests/Fixtures/owned-review-case/tests/order_total.php');
        $context = ['repository' => DedicatedTestRepo::requireName(), 'issueNumber' => 1,
            'issueUrl' => 'https://github.com/' . DedicatedTestRepo::requireName() . '/issues/1',
            'commit' => DedicatedTestRepo::requireBaseCommit(), 'title' => 'Zero-quantity order bug',
            'body' => 'The total is wrong when quantity is zero.', 'path' => 'src/OrderTotal.php',
            'source' => $source, 'sourceSha256' => hash('sha256', $source),
            'testSource' => $test, 'testSha256' => hash('sha256', $test)];
        return [$context, $source, $test];
    }

    private function useCase(MockHttpClient $http, SingleUseReviewApproval $approvals): ProposeSuggestionUseCase
    {
        $budget = new RequestBudget($this->dir . '/budget', 500000, 10);
        return new ProposeSuggestionUseCase(
            new IssueSuggestion($http, $budget),
            $budget,
            $approvals,
            new ServerHeldModelProposal($this->dir . '/proposals'),
            new PrivateProposalStore($this->dir . '/private', new Encrypter(random_bytes(32), 'aes-256-cbc'), random_bytes(32)),
            $http,
            dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/src/OrderTotal.php'
        );
    }

    private function liveHttp(array $context, string $source, string $test): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($context, $source, $test): MockResponse {
            if ($method === 'GET' && str_ends_with($url, '/issues/1')) {
                return new MockResponse(json_encode(['number' => 1, 'state' => 'open', 'title' => $context['title'], 'body' => $context['body']]));
            }
            if ($method === 'GET' && str_contains($url, '/contents/src/OrderTotal.php')) {
                return new MockResponse(json_encode(['content' => base64_encode($source)]));
            }
            if ($method === 'GET' && str_contains($url, '/contents/tests/order_total.php')) {
                return new MockResponse(json_encode(['content' => base64_encode($test)]));
            }
            if ($method === 'POST' && str_starts_with($url, 'https://inference.baseten.co/')) {
                $content = json_encode(['path' => $context['path'], 'find' => "max(1, \$item['quantity'])",
                    'replace' => "\$item['quantity']", 'explanation' => 'Use actual quantity.']);
                return new MockResponse(json_encode(['model' => 'meta-models/muse-spark-open-1026',
                    'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30]]));
            }
            throw new \RuntimeException('Unexpected network request: ' . $method . ' ' . $url);
        });
    }

    /** @return array<string,mixed> */
    private function privateInput(array $context, string $digest, string $token, bool $consent = true): array
    {
        return ['digest' => $digest, 'approved' => true, 'approval_token' => $token,
            'offline_reference' => false, 'private_consent' => $consent, 'candidate_sha' => null,
            'session_auth' => ['token' => 'mock-token', 'user' => ['id' => 7], 'repositories' => [
                ['fullName' => $context['repository'], 'isPrivate' => true]]],
            'verified_access' => ['userId' => 7, 'repositories' => [strtolower($context['repository']) => true]],
            'saved_approval_token' => $token, 'approval_user_id' => 7, 'github_token' => 'mock-token'];
    }

    public function testPrivateDedicatedLivePathRecordsProvenance(): void
    {
        [$context, $source, $test] = $this->dedicatedContext();
        $http = $this->liveHttp($context, $source, $test);
        $approvals = new SingleUseReviewApproval($this->dir . '/approvals');
        $useCase = $this->useCase($http, $approvals);
        $digest = (new IssueSuggestion($http, new RequestBudget($this->dir . '/budget-peek', 500000, 10)))->preview($context)['digest'];
        $token = $approvals->issue($context['repository'], 1, $context['commit'], $digest)['token'];

        $result = $useCase->execute(ProposeSuggestionInput::fromRaw(
            $this->privateInput($context, $digest, $token), $context, true, true
        ));

        self::assertTrue($result->ok, $result->error);
        self::assertSame('meta-models/muse-spark-open-1026', $result->suggestion['model']);
        self::assertTrue($result->suggestion['privateProvenanceRecorded']);
        self::assertTrue($result->approvalConsumed);
        self::assertTrue($result->privateApprovalConsumed);
        self::assertCount(1, glob($this->dir . '/private/*.enc'));
    }

    public function testMissingConsentFailsWithoutConsumingApproval(): void
    {
        [$context, $source, $test] = $this->dedicatedContext();
        $http = $this->liveHttp($context, $source, $test);
        $approvals = new SingleUseReviewApproval($this->dir . '/approvals');
        $useCase = $this->useCase($http, $approvals);
        $digest = (new IssueSuggestion($http, new RequestBudget($this->dir . '/budget-peek', 500000, 10)))->preview($context)['digest'];
        $token = $approvals->issue($context['repository'], 1, $context['commit'], $digest)['token'];

        $result = $useCase->execute(ProposeSuggestionInput::fromRaw(
            $this->privateInput($context, $digest, $token, false), $context, true, true
        ));

        self::assertFalse($result->ok);
        self::assertSame('Approve sending this private test issue and selected file to the displayed provider.', $result->error);
        self::assertFalse($result->approvalConsumed);
        self::assertSame([], glob($this->dir . '/private/*.enc') ?: []);
    }

    public function testChangedContentFailsAfterRefetch(): void
    {
        [$context, $source, $test] = $this->dedicatedContext();
        $drifted = $source . "\n// drift";
        $http = $this->liveHttp($context, $drifted, $test);
        $approvals = new SingleUseReviewApproval($this->dir . '/approvals');
        $useCase = $this->useCase($http, $approvals);
        $peek = new IssueSuggestion($http, new RequestBudget($this->dir . '/budget-peek', 500000, 10));
        $digest = $peek->preview($context)['digest'];
        $token = $approvals->issue($context['repository'], 1, $context['commit'], $digest)['token'];

        $result = $useCase->execute(ProposeSuggestionInput::fromRaw(
            $this->privateInput($context, $digest, $token), $context, true, true
        ));

        self::assertFalse($result->ok);
        // Drifted bytes fail the pinned source check during re-fetch.
        self::assertNotSame('', $result->error);
        self::assertFalse($result->approvalConsumed);
    }

    public function testWrongPreviewDigestFailsClosed(): void
    {
        [$context, $source, $test] = $this->dedicatedContext();
        $http = new MockHttpClient(fn(): MockResponse => throw new \RuntimeException('No network may happen.'));
        $approvals = new SingleUseReviewApproval($this->dir . '/approvals');
        $useCase = $this->useCase($http, $approvals);
        $raw = $this->privateInput($context, str_repeat('0', 64), str_repeat('1', 64));

        $result = $useCase->execute(ProposeSuggestionInput::fromRaw($raw, $context, true, true));

        self::assertFalse($result->ok);
        self::assertSame('Review and approve the exact request first.', $result->error);
        self::assertFalse($result->approvalConsumed);
    }

    public function testOfflineReferenceNeedsNoNetworkKeyOrApproval(): void
    {
        [$context] = $this->dedicatedContext();
        $http = new MockHttpClient(fn(): MockResponse => throw new \RuntimeException('No network may happen.'));
        $approvals = new SingleUseReviewApproval($this->dir . '/approvals');
        $useCase = $this->useCase($http, $approvals);
        $peek = new IssueSuggestion($http, new RequestBudget($this->dir . '/budget-peek', 500000, 10));
        $digest = $peek->preview($context)['digest'];
        $raw = $this->privateInput($context, $digest, 'unused');
        $raw['offline_reference'] = true;
        unset($raw['session_auth']['token']);

        $result = $useCase->execute(ProposeSuggestionInput::fromRaw($raw, $context, true, true));

        self::assertTrue($result->ok, $result->error);
        self::assertSame('offline_reference_example', $result->suggestion['status']);
        self::assertNull($result->suggestion['model']);
        self::assertFalse($result->approvalConsumed);
    }

    public function testOwnedFixtureLivePathWithoutPrivateProvenance(): void
    {
        [$context, $source, $test] = $this->dedicatedContext();
        $http = $this->liveHttp($context, $source, $test);
        $approvals = new SingleUseReviewApproval($this->dir . '/approvals');
        $useCase = $this->useCase($http, $approvals);
        $peek = new IssueSuggestion($http, new RequestBudget($this->dir . '/budget-peek', 500000, 10));
        $digest = $peek->preview($context)['digest'];
        $token = $approvals->issue($context['repository'], 1, $context['commit'], $digest)['token'];
        $raw = $this->privateInput($context, $digest, $token);
        $raw['session_auth']['repositories'] = [['fullName' => $context['repository'], 'isPrivate' => false]];

        $result = $useCase->execute(ProposeSuggestionInput::fromRaw($raw, $context, true, false));

        self::assertTrue($result->ok, $result->error);
        self::assertSame('meta-models/muse-spark-open-1026', $result->suggestion['model']);
        self::assertArrayNotHasKey('privateProvenanceRecorded', $result->suggestion);
        self::assertTrue($result->approvalConsumed);
        self::assertFalse($result->privateApprovalConsumed);
    }

    public function testClientSideSessionKeepsPrivateContextLocal(): void
    {
        [$context, $source, $test] = $this->dedicatedContext();
        $http = new MockHttpClient(fn(): MockResponse => throw new \RuntimeException('No network may happen.'));
        $approvals = new SingleUseReviewApproval($this->dir . '/approvals');
        $useCase = $this->useCase($http, $approvals);
        $peek = new IssueSuggestion($http, new RequestBudget($this->dir . '/budget-peek', 500000, 10));
        $digest = $peek->preview($context)['digest'];
        $raw = $this->privateInput($context, $digest, str_repeat('2', 64));
        $raw['uses_server_session'] = false;

        $result = $useCase->execute(ProposeSuggestionInput::fromRaw($raw, $context, true, true));

        self::assertFalse($result->ok);
        self::assertSame('Private or unverified repository content stays local and cannot be sent to a model.', $result->error);
        self::assertFalse($result->approvalConsumed);
    }

    public function testInputRejectsUnapprovedOrMissingDigest(): void
    {
        [$context] = $this->dedicatedContext();
        try {
            ProposeSuggestionInput::fromRaw(['approved' => 'yes'], $context, true, true);
            self::fail('Unapproved input must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Review and approve the request first.', $e->getMessage());
        }
        try {
            ProposeSuggestionInput::fromRaw(['approved' => true], $context, true, true);
            self::fail('Missing digest must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Review and approve the request first.', $e->getMessage());
        }
    }
}
