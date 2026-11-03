<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Hari\AbilityManifest;
use Hari\HariError;
use Hari\Registry;
use Hari\Skill;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\Agent\PublicCardProposal;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\Evidence\CanonicalJson;
use ProofPatch\GitHub\PublicCardPreview;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EngineeringWorkflowRoutingTest extends TestCase
{
    private string $directory;
    private int $httpCalls = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/proofpatch-workflow-routing-'.bin2hex(random_bytes(8));
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (): MockResponse {
            ++$this->httpCalls;
            self::fail('Workflow preview and resolution failure must not reach HTTP.');
        });
    }

    private function sentinel(): Skill
    {
        return new class implements Skill {
            public array $inputs = [];
            public function id(): string { return 'engineering.workflow'; }
            public function version(): int { return 1; }
            public function capabilities(): array { return []; }
            public function describe(): string { return 'Records workflow selection without external effects.'; }
            public function invoke(array $input): array
            {
                $this->inputs[] = $input;
                return ['guidance' => 'Sentinel '.$input['task'].' guidance from the borrowed skill.'];
            }
        };
    }

    private function registry(Skill $skill, bool $allowBorrow = true): Registry
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('engineering', 1, ['engineering.workflow'], [], []));
        foreach (['proofpatch-review', 'workbench'] as $borrower) {
            $registry->registerAbility(new AbilityManifest($borrower, 1, [], $allowBorrow ? ['engineering.workflow' => 1] : [], []));
        }
        $registry->provideSkill('engineering', $skill);
        $registry->freeze();
        return $registry;
    }

    private function pull(): array
    {
        return ['repository' => 'example/project', 'number' => 12, 'title' => 'Guard quantity',
            'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40), 'isPrivate' => false];
    }

    private function patch(): array
    {
        return $this->pull() + ['changedFiles' => 1, 'filesTruncated' => false, 'files' => [
            ['name' => 'src/Quantity.php', 'status' => 'available', 'patch' => "@@ -1 +1 @@\n-return 1;\n+return 2;"],
        ]];
    }

    private function issue(): array
    {
        $source = "<?php\nreturn 1;\n";
        return ['repository' => 'example/project', 'issueNumber' => 12,
            'issueUrl' => 'https://github.com/example/project/issues/12', 'title' => 'Correct quantity',
            'body' => 'The quantity should be two.', 'commit' => str_repeat('a', 40),
            'path' => 'src/Quantity.php', 'source' => $source, 'sourceSha256' => hash('sha256', $source)];
    }

    private function card(): array
    {
        return ['id' => 7, 'userId' => 3, 'repository' => 'example/project',
            'title' => 'Correct quantity', 'description' => 'The quantity should be two.'];
    }

    private function cardSnapshot(): array
    {
        $card = $this->card();
        $context = ['kind' => 'local_task_card', 'cardId' => $card['id'],
            'cardVersion' => PublicCardPreview::cardVersion($card), 'repository' => $card['repository'],
            'title' => $card['title'], 'description' => $card['description'], 'commit' => str_repeat('a', 40),
            'path' => 'src/Quantity.php', 'source' => $this->issue()['source'],
            'sourceSha256' => $this->issue()['sourceSha256']];
        return ['context' => $context, 'digest' => hash('sha256', CanonicalJson::encode($context)),
            'status' => 'local_preview_not_sent'];
    }

    private function agent(string $kind, ?Registry $registry): PullRequestReview|IssueSuggestion|PublicCardProposal
    {
        $budget = new RequestBudget($this->directory.'/budget');
        return match ($kind) {
            'review' => new PullRequestReview($this->http(), $budget, $registry),
            'issue' => new IssueSuggestion($this->http(), $budget, $registry),
            'card' => new PublicCardProposal($this->http(), new PublicCardPreview($this->http()), $budget,
                new SingleUseReviewApproval($this->directory.'/approvals'), $registry),
        };
    }

    private function preview(string $kind, PullRequestReview|IssueSuggestion|PublicCardProposal $agent): array
    {
        return match ($kind) {
            'review' => $agent->preview($this->pull(), $this->patch()),
            'issue' => $agent->preview($this->issue()),
            'card' => $agent->preview($this->cardSnapshot(), $this->card()),
        };
    }

    public function testEveryProviderPreviewUsesTheResolvedRoleGuidanceWithoutSharingRepositoryContextWithTheSkill(): void
    {
        foreach (['review' => 'review', 'issue' => 'repair', 'card' => 'repair'] as $kind => $task) {
            $skill = $this->sentinel();
            $preview = $this->preview($kind, $this->agent($kind, $this->registry($skill)));
            $baseline = $this->preview($kind, $this->agent($kind, null));

            self::assertSame([['task' => $task]], $skill->inputs, $kind);
            self::assertSame(['system', 'user'], array_column($preview['body']['messages'], 'role'), $kind);
            self::assertStringContainsString('Sentinel '.$task.' guidance from the borrowed skill.', $preview['body']['messages'][0]['content'], $kind);
            self::assertSame($baseline['body']['messages'][1], $preview['body']['messages'][1], $kind);
            self::assertSame($baseline['destination'], $preview['destination'], $kind);
            self::assertSame($baseline['body']['max_tokens'], $preview['body']['max_tokens'], $kind);
            self::assertSame($baseline['body']['model'], $preview['body']['model'], $kind);
            self::assertArrayNotHasKey('tools', $preview['body'], $kind);
            self::assertNotSame($baseline['digest'], $preview['digest'], $kind);
            self::assertSame(hash('sha256', CanonicalJson::encode([
                'destination' => $preview['destination'], 'body' => $preview['body'],
            ])), $preview['digest'], $kind);
        }
        self::assertSame(0, $this->httpCalls);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testAnUndeclaredBorrowCannotReachProviderExecutionOrConsumeCreditAndApprovalState(): void
    {
        foreach (['review', 'issue', 'card'] as $kind) {
            $skill = $this->sentinel();
            $agent = $this->agent($kind, $this->registry($skill, false));
            $digest = $this->preview($kind, $this->agent($kind, null))['digest'];
            try {
                match ($kind) {
                    'review' => $agent->review($this->pull(), $this->patch(), $digest, 'test-key-1234567890'),
                    'issue' => $agent->propose($this->issue(), $digest, 'test-key-1234567890'),
                    'card' => $agent->propose($this->cardSnapshot(), fn () => $this->card(), true,
                        str_repeat('c', 64), $digest, 'test-key-1234567890',
                        static fn () => self::fail('Reservation must not be created.'),
                        static fn () => self::fail('Reservation must not be finished.')),
                };
                self::fail('An undeclared workflow borrow reached execution: '.$kind);
            } catch (HariError $error) {
                self::assertSame('borrow_not_declared', $error->code(), $kind);
            }
            self::assertSame([], $skill->inputs, $kind);
        }
        self::assertSame(0, $this->httpCalls);
        self::assertDirectoryDoesNotExist($this->directory);
    }
}
