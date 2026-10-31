<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/**
 * Deterministic issue-report completeness analysis.
 *
 * Scores what the reporter actually wrote (title, description, repro steps,
 * expected-vs-actual, environment, pasted evidence) so the workbench can say
 * precisely what is missing instead of guessing. Pure function, no model, no
 * network. It never claims an issue is reproducible or fixable.
 */
final class IssueCompleteness
{
    private const MAX_ANALYZE_BYTES = 32768;

    /**
     * @return array{
     *     score: int,
     *     verdict: 'complete'|'needs_info'|'empty',
     *     checks: list<array{key: string, label: string, passed: bool, detail: string}>,
     *     missing: list<string>,
     *     questions: list<string>
     * }
     */
    public static function analyze(string $title, string $body): array
    {
        $title = trim(mb_substr($title, 0, 2000));
        $body = trim(mb_substr($body, 0, self::MAX_ANALYZE_BYTES));
        $textOnly = trim((string) preg_replace('/!\[[^\]]*\]\([^)]+\)|<img\b[^>]*>/i', '', $body));
        $hasMedia = $textOnly !== $body
            || preg_match('~https?://\S+\.(?:png|jpe?g|gif|webp)\b~i', $body) === 1;

        $titleWords = $title === '' ? 0 : count(preg_split('/\s+/', $title) ?: []);
        $hasTitle = mb_strlen($title) >= 12 && $titleWords >= 3;
        $hasDescription = mb_strlen($textOnly) >= 40;
        $hasRepro = preg_match('/steps?\s+to\s+repro|repro(duce|duction|ducible)|how\s+to\s+(reproduce|replicate)|^\s*(?:str|repro)\s*:/im', $body) === 1
            || preg_match('/^\s*(?:\d+[.)]|[-*])\s+\S+/m', $body) === 1;
        $hasExpectedActual = preg_match('/expected|should\b/i', $body) === 1
            && preg_match('/actual|instead|but\s+(it|the|i)/i', $body) === 1;
        $hasEnvironment = preg_match('/version|environment|\bos\b|php\s*\d|browser|platform|commit|revision|\bsha\b|device/i', $body) === 1;
        $hasEvidence = str_contains($body, '```')
            || preg_match('~\b[A-Za-z0-9_./-]+\.(?:php|ts|tsx|js|jsx|py|rb|go|rs|java|css|html|json|ya?ml)\b~', $body) === 1
            || preg_match('/Exception|Traceback|Fatal error|Stack trace|\bat\s+\S+:\d+/i', $body) === 1;

        $checks = [
            ['key' => 'title', 'label' => 'Descriptive title', 'passed' => $hasTitle,
                'detail' => $hasTitle ? 'Title summarizes the problem.' : 'Title is missing or too short to summarize the problem.'],
            ['key' => 'description', 'label' => 'Written description', 'passed' => $hasDescription,
                'detail' => $hasDescription ? 'Description has substance.' : 'No written description yet.'],
            ['key' => 'repro', 'label' => 'Reproduction steps', 'passed' => $hasRepro,
                'detail' => $hasRepro ? 'Steps or an ordered list were found.' : 'No reproduction steps found.'],
            ['key' => 'expected_actual', 'label' => 'Expected vs actual', 'passed' => $hasExpectedActual,
                'detail' => $hasExpectedActual ? 'Expected and actual behavior are both stated.' : 'Expected and actual behavior are not both stated.'],
            ['key' => 'environment', 'label' => 'Environment details', 'passed' => $hasEnvironment,
                'detail' => $hasEnvironment ? 'Version or environment hints are present.' : 'No version or environment hints.'],
            ['key' => 'evidence', 'label' => 'Logs or code evidence', 'passed' => $hasEvidence,
                'detail' => $hasEvidence ? 'Logs, traces, or file references are present.' : 'No pasted logs, traces, or file references.'],
        ];

        if ($hasMedia && mb_strlen($textOnly) < 120) {
            $checks[] = ['key' => 'media_text', 'label' => 'Text alongside screenshots', 'passed' => false,
                'detail' => 'Screenshots are attached but the written repro is thin; screenshots stay local and are not read automatically.'];
        }

        $weights = ['title' => 10, 'description' => 20, 'repro' => 30, 'expected_actual' => 15, 'environment' => 10, 'evidence' => 15];
        $score = 0;
        foreach ($checks as $check) {
            if ($check['passed']) {
                $score += $weights[$check['key']] ?? 0;
            }
        }

        if (!$hasDescription && !$hasTitle) {
            $verdict = 'empty';
        } else {
            $verdict = $score >= 70 ? 'complete' : 'needs_info';
        }

        $missing = [];
        foreach ($checks as $check) {
            if (!$check['passed']) {
                $missing[] = $check['label'];
            }
        }

        $questionMap = [
            'description' => 'Can you describe what you were doing when this happened?',
            'repro' => 'What are the exact steps to reproduce this, starting from a clean checkout?',
            'expected_actual' => 'What did you expect to happen, and what happened instead?',
            'environment' => 'Which version, operating system, and environment does this happen on?',
            'evidence' => 'Can you paste the relevant log output or code snippet as text?',
            'media_text' => 'Could you add the written steps to reproduce alongside the screenshots?',
        ];
        $questions = [];
        foreach ($checks as $check) {
            // The screenshot question is the most actionable one: ask it first.
            if ($check['key'] === 'media_text' && !$check['passed']) {
                array_unshift($questions, $questionMap['media_text']);
            } elseif (!$check['passed'] && isset($questionMap[$check['key']])) {
                $questions[] = $questionMap[$check['key']];
            }
        }
        $questions = array_slice(array_values(array_unique($questions)), 0, 3);

        return [
            'score' => min(100, $score),
            'verdict' => $verdict,
            'checks' => $checks,
            'missing' => $missing,
            'questions' => $questions,
        ];
    }
}
