<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

require_once dirname(__DIR__, 2).'/app/GitHub/ObservedDraftApproval.php';

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoFetcher;
use ProofPatch\GitHub\ObservedDraftApproval;
use ProofPatch\Verifier\OwnedCaseVerifier;

final class ObservedDraftApprovalTest extends TestCase
{
    private string $dir;
    private array $context;
    private array $suggestion;
    private array $observation;
    private ObservedDraftApproval $approval;

    protected function setUp(): void
    {
        putenv('PROOFPATCHER_TEST_REPO=example/demo');
        putenv('PROOFPATCHER_TEST_BASE_COMMIT='.str_repeat('a', 40));
        $this->dir = sys_get_temp_dir().'/pp-observed-'.bin2hex(random_bytes(8));
        $this->approval = new ObservedDraftApproval($this->dir, str_repeat('s', 32));
        $this->context = ['repository' => DedicatedTestRepo::requireName(), 'issueNumber' => 1,
            'commit' => DedicatedTestRepo::requireBaseCommit(), 'path' => DedicatedTestRepoFetcher::SOURCE_PATH,
            'sourceSha256' => DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256, 'title' => 'Order total', 'body' => 'A test issue'];
        $this->suggestion = ['candidate' => ['source' => '<?php function orderTotal(array $items): int { return 0; }'],
            'privateProvenanceRecorded' => true, 'privateProvenanceId' => str_repeat('a', 32),
            'model' => 'muse', 'requestDigest' => str_repeat('b', 64)];
        $this->observation = ['provider' => 'modal', 'scope' => 'finite_local_fixture', 'status' => 'passed',
            'baseline' => 'fixture_failed', 'negative' => 'fixture_failed', 'candidate' => 'fixture_passed',
            'calls' => ['baseline' => 3, 'negative' => 3, 'candidate' => 3],
            'candidateSha256' => hash('sha256', $this->suggestion['candidate']['source']),
            'harnessSha256' => OwnedCaseVerifier::KNOWN_TEST_SHA256,
            'baselineSha256' => DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) @unlink($path);
        @rmdir($this->dir);
        putenv('PROOFPATCHER_TEST_REPO');
        putenv('PROOFPATCHER_TEST_BASE_COMMIT');
    }

    public function testApprovalIsPrivateBoundAndSingleUse(): void
    {
        $token = $this->approval->issue(7, $this->context, $this->suggestion, $this->observation);
        self::assertSame(0600, fileperms($this->dir.'/'.$token.'.json') & 0777);
        self::assertSame(7, $this->approval->consume($token, 7, $this->context, $this->suggestion)['userId']);
        $this->expectException(\RuntimeException::class);
        $this->approval->consume($token, 7, $this->context, $this->suggestion);
    }

    public function testChangedCandidateIsRejectedAndTokenConsumed(): void
    {
        $token = $this->approval->issue(7, $this->context, $this->suggestion, $this->observation);
        $changed = $this->suggestion;
        $changed['candidate']['source'] .= ' ';
        try { $this->approval->consume($token, 7, $this->context, $changed); self::fail('Changed source accepted.'); }
        catch (\RuntimeException) {}
        $this->expectException(\RuntimeException::class);
        $this->approval->consume($token, 7, $this->context, $this->suggestion);
    }

    public function testSimulatedOrIncompleteObservationsCannotIssueApproval(): void
    {
        $this->observation['provider'] = 'simulated';
        $this->expectException(\InvalidArgumentException::class);
        $this->approval->issue(7, $this->context, $this->suggestion, $this->observation);
    }

    public function testTamperedRecordIsRejected(): void
    {
        $token = $this->approval->issue(7, $this->context, $this->suggestion, $this->observation);
        $path = $this->dir.'/'.$token.'.json';
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $data['binding']['userId'] = 8;
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        $this->approval->consume($token, 8, $this->context, $this->suggestion);
    }
}
