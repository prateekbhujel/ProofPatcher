<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\Support\IssueCompleteness;

/**
 * Pure maintainer decision for one open issue. Decides what autopilot should
 * do before anything is posted or proposed: nothing here writes anywhere.
 * Priority: existing fix work beats asking questions; asking beats fixing.
 */
final class IssueMaintainerTriage
{
    private const DUPLICATE_THRESHOLD = 0.75;

    /**
     * @param array{repository?: string, number?: int, title?: string, body?: string} $issue
     * @param list<array{number?: int, title?: string}> $otherOpenIssues
     * @param list<array{number?: int, state?: string, isMerged?: bool, title?: string, body?: string}> $pulls
     * @return array{action: string, reason: string, questions: list<string>, duplicateOf: ?int, pullNumber: ?int}
     */
    public static function decide(array $issue, array $otherOpenIssues, array $pulls, bool $fixEnabled, ?string $demoRepository): array
    {
        $number = (int) ($issue['number'] ?? 0);
        $base = ['questions' => [], 'duplicateOf' => null, 'pullNumber' => null];

        foreach ($pulls as $pull) {
            if (!self::referencesIssue($pull, $number)) {
                continue;
            }
            $pullNumber = (int) ($pull['number'] ?? 0);
            if ($pullNumber < 1) {
                continue;
            }
            if (($pull['isMerged'] ?? false) === true) {
                return array_merge($base, ['action' => 'already_fixed', 'pullNumber' => $pullNumber,
                    'reason' => "Merged pull request #{$pullNumber} mentions this issue; check whether it resolves the problem."]);
            }
            if (($pull['state'] ?? 'open') === 'open') {
                return array_merge($base, ['action' => 'has_open_pr', 'pullNumber' => $pullNumber,
                    'reason' => "Open pull request #{$pullNumber} already references this issue."]);
            }
        }

        $bestDuplicate = null;
        $bestScore = 0.0;
        foreach ($otherOpenIssues as $other) {
            $otherNumber = (int) ($other['number'] ?? 0);
            if ($otherNumber < 1 || $otherNumber === $number) {
                continue;
            }
            $score = self::titleSimilarity((string) ($issue['title'] ?? ''), (string) ($other['title'] ?? ''));
            if ($score >= self::DUPLICATE_THRESHOLD && $score > $bestScore) {
                $bestScore = $score;
                $bestDuplicate = $otherNumber;
            }
        }
        if ($bestDuplicate !== null) {
            return array_merge($base, ['action' => 'duplicate', 'duplicateOf' => $bestDuplicate,
                'reason' => "Title closely matches open issue #{$bestDuplicate}."]);
        }

        $completeness = IssueCompleteness::analyze((string) ($issue['title'] ?? ''), (string) ($issue['body'] ?? ''));
        if ($completeness['verdict'] !== 'complete') {
            return array_merge($base, ['action' => 'needs_info', 'questions' => $completeness['questions'],
                'reason' => 'Issue is missing: '.implode(', ', $completeness['missing']).'.']);
        }

        $repository = (string) ($issue['repository'] ?? '');
        if ($fixEnabled && $demoRepository !== null && strcasecmp($repository, $demoRepository) === 0
            && $number === DedicatedTestRepoPublisher::AUTHORIZED_ISSUE_NUMBER) {
            return array_merge($base, ['action' => 'fix_eligible',
                'reason' => 'Complete issue on the dedicated demo repository; the verified propose-and-check workflow can prepare a draft PR. Publication still requires its server-held approval.']);
        }

        return array_merge($base, ['action' => 'no_fix_scope',
            'reason' => 'Issue is complete, but automatic fixing is off or outside the dedicated demo scope. A maintainer can run the fix flow manually.']);
    }

    /** @param array{title?: string, body?: string} $pull */
    private static function referencesIssue(array $pull, int $issueNumber): bool
    {
        if ($issueNumber < 1) {
            return false;
        }
        $text = ((string) ($pull['title'] ?? ''))."\n".((string) ($pull['body'] ?? ''));
        return preg_match('/#'.$issueNumber.'(?!\d)/', $text) === 1;
    }

    private static function titleSimilarity(string $a, string $b): float
    {
        $tokens = static function (string $title): array {
            $words = preg_split('/[^a-z0-9]+/', strtolower($title)) ?: [];
            return array_values(array_unique(array_filter($words, static fn (string $w): bool => strlen($w) > 2)));
        };
        $left = $tokens($a);
        $right = $tokens($b);
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $shared = count(array_intersect($left, $right));
        $union = count(array_unique(array_merge($left, $right)));
        return $union === 0 ? 0.0 : $shared / $union;
    }
}
