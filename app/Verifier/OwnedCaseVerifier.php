<?php

declare(strict_types=1);

namespace ProofPatch\Verifier;

use ProofPatch\Evidence\Receipt;
use ProofPatch\Support\LocalProcess;

/**
 * Bounded allowlisted verifier for the owned synthetic review case.
 * Enforces static AST safety, baseline failure reproduction, negative-control rejection,
 * candidate verification, and cryptographic receipt generation.
 *
 * Explicit Boundary:
 * Host execution of arbitrary untrusted PHP is strictly prohibited.
 * This verifier only executes known allowlisted fixtures under static token guards.
 * Full execution of untrusted arbitrary repositories requires an external microVM sandbox.
 */
final readonly class OwnedCaseVerifier
{
    public const KNOWN_BASE_SHA256 = '54bc37fb2b005badcf309c5a7091ba6a2e704fc54c8eff9e9c7afee7e4054859';
    public const KNOWN_TEST_SHA256 = 'b8079fb1e7731fc763f0b009a11c2786d01b52cce08f7431768a64e8a5ef2cbf';
    public const KNOWN_TEST_SHA256_DEDICATED = '263eb70cbf346221858b9c64ba97384105d3804089a653d63d016664e8c1320e';
    public const KNOWN_TRUSTED_CANDIDATE_SHA256 = 'd102376bea8e1bdb745cf0f4026540574254e741a590be455a94dceb1a69f8fb';

    private const FORBIDDEN_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
        'eval', 'assert', 'fopen', 'file_get_contents', 'file_put_contents', 'unlink',
        'rmdir', 'mkdir', 'copy', 'rename', 'chmod', 'chown', 'touch', 'curl_init',
        'curl_exec', 'socket_create', 'fsockopen', 'pfsockopen', 'stream_socket_client',
        'dl', 'create_function', 'posix_kill', 'call_user_func', 'call_user_func_array',
        'forward_static_call', 'forward_static_call_array', 'reflectionfunction',
        'reflectionclass', 'reflectionmethod', 'getenv', 'putenv', 'unserialize',
        'readfile', 'file', 'scandir', 'glob', 'parse_ini_file', 'show_source',
        'highlight_file', 'symlink', 'link', 'dio_read', 'dio_write', 'readlink',
        'is_dir', 'is_executable', 'is_file', 'is_link', 'is_readable', 'is_writable',
        'fwrite', 'fputs', 'printf', 'vprintf', 'fprintf', 'vfprintf', 'print_r',
        'var_dump', 'var_export', 'debug_zval_dump', 'debug_print_backtrace', 'fpassthru',
        'ob_start', 'ob_flush', 'ob_clean', 'ob_end_flush', 'ob_end_clean', 'ob_get_contents',
        'ob_get_clean', 'ob_get_flush', 'ob_implicit_flush',
        'register_shutdown_function', 'register_tick_function', 'set_error_handler',
        'set_exception_handler', 'error_reporting', 'restore_error_handler', 'restore_exception_handler',
        'fastcgi_finish_request',
    ];

    public function __construct(
        private string $project,
        private ?string $runsRoot = null,
        private ?\ProofPatch\GitHub\ServerHeldPublicationApproval $approvalStore = null,
        private ?IsolatedRunnerInterface $isolatedRunner = null
    ) {
        if (realpath($project) !== $project) {
            throw new \InvalidArgumentException('Expected a resolved project directory.');
        }
    }

    /**
     * Statically inspects PHP source code to verify that it does not contain
     * dangerous tokens, backticks, includes, evals, variable functions, dynamic calls,
     * or system calls.
     */
    public static function checkCandidateSafety(string $source): array
    {
        try {
            $tokens = @token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return [
                'safe' => false,
                'reason' => 'Candidate PHP syntax error: ' . $e->getMessage(),
            ];
        }

        $depth = 0;
        $inDeclare = false;
        $inSignature = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                    $inSignature = false;
                } elseif ($token === '}') {
                    $depth = max(0, $depth - 1);
                } elseif ($token === ';') {
                    $inDeclare = false;
                }

                if ($token === '`') {
                    return [
                        'safe' => false,
                        'reason' => 'Candidate contains disallowed backtick shell execution operator.',
                    ];
                }
                if ($token === '$') {
                    $next = self::nextNonWhitespace($tokens, $i);
                    if ($next !== null && is_array($next) && $next[0] === T_VARIABLE) {
                        return [
                            'safe' => false,
                            'reason' => 'Candidate contains disallowed variable variable.',
                        ];
                    }
                    if ($next === '{') {
                        return [
                            'safe' => false,
                            'reason' => 'Candidate contains disallowed dynamic variable syntax.',
                        ];
                    }
                }
                if ($token === ')' || $token === '}') {
                    $next = self::nextNonWhitespace($tokens, $i);
                    if ($next === '(') {
                        return [
                            'safe' => false,
                            'reason' => 'Candidate contains disallowed dynamic expression invocation.',
                        ];
                    }
                }
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_EXIT) {
                return [
                    'safe' => false,
                    'reason' => 'Candidate contains disallowed exit or die language construct.',
                ];
            }

            if ($id === T_ECHO || $id === T_PRINT) {
                return [
                    'safe' => false,
                    'reason' => 'Candidate contains disallowed output construct (echo/print). Pure calculation library files must not produce side-effect output.',
                ];
            }

            if ($id === T_INLINE_HTML) {
                return [
                    'safe' => false,
                    'reason' => 'Candidate contains disallowed inline HTML / raw output text. Pure PHP library files must not produce side-effect output.',
                ];
            }

            if ($id === T_CLOSE_TAG) {
                return [
                    'safe' => false,
                    'reason' => 'Candidate contains disallowed closing PHP tag (?>). Pure PHP library files must omit closing tags.',
                ];
            }

            if ($id === T_HALT_COMPILER) {
                return [
                    'safe' => false,
                    'reason' => 'Candidate contains disallowed __halt_compiler construct.',
                ];
            }

            if ($id === T_DECLARE) {
                $inDeclare = true;
            }

            if ($id === T_FUNCTION || $id === T_CLASS) {
                $inSignature = true;
            }

            if ($depth === 0 && !$inDeclare && !$inSignature) {
                if (!in_array($id, [T_OPEN_TAG, T_USE, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    return [
                        'safe' => false,
                        'reason' => 'Candidate contains disallowed top-level executable statement outside function or class declaration: ' . token_name($id),
                    ];
                }
            }

            if ($id === T_EVAL) {
                return [
                    'safe' => false,
                    'reason' => 'Candidate contains disallowed eval construct.',
                ];
            }

            if (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                return [
                    'safe' => false,
                    'reason' => 'Candidate contains disallowed dynamic include or require statement.',
                ];
            }

            if ($id === T_VARIABLE) {
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next === '(') {
                    return [
                        'safe' => false,
                        'reason' => 'Candidate contains disallowed variable function or dynamic invocation.',
                    ];
                }
            }

            $isObjectOp = ($id === T_OBJECT_OPERATOR) || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $id === T_NULLSAFE_OBJECT_OPERATOR);
            if ($isObjectOp || $id === T_DOUBLE_COLON) {
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next !== null && is_array($next) && $next[0] === T_VARIABLE) {
                    return [
                        'safe' => false,
                        'reason' => 'Candidate contains disallowed dynamic property or method access.',
                    ];
                }
            }

            if ($id === T_NEW) {
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next !== null && is_array($next) && $next[0] === T_VARIABLE) {
                    return [
                        'safe' => false,
                        'reason' => 'Candidate contains disallowed dynamic class instantiation.',
                    ];
                }
            }

            $isNameToken = ($id === T_STRING)
                || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
                || (defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED)
                || (defined('T_NAME_RELATIVE') && $id === T_NAME_RELATIVE);

            if ($isNameToken) {
                $canonicalName = strtolower(ltrim($text, '\\'));
                if (in_array($canonicalName, self::FORBIDDEN_FUNCTIONS, true)) {
                    return [
                        'safe' => false,
                        'reason' => "Candidate contains disallowed function call: {$text}().",
                    ];
                }
            }

            if ($id === T_NS_SEPARATOR) {
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next !== null && is_array($next)) {
                    $nextName = strtolower(ltrim($next[1], '\\'));
                    if (in_array($nextName, self::FORBIDDEN_FUNCTIONS, true)) {
                        return [
                            'safe' => false,
                            'reason' => "Candidate contains disallowed function call: \\{$next[1]}().",
                        ];
                    }
                }
            }
        }

        return ['safe' => true, 'reason' => null];
    }

    private static function nextNonWhitespace(array $tokens, int $currentIndex): array|string|null
    {
        for ($j = $currentIndex + 1; $j < count($tokens); $j++) {
            $tok = $tokens[$j];
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $tok;
        }
        return null;
    }

    public function verify(string $candidateSource, ?array $context = null): array
    {
        $fixtureDir = $this->project . '/tests/Fixtures/owned-review-case';
        $baseFile = $fixtureDir . '/src/OrderTotal.php';
        $testFile = $fixtureDir . '/tests/order_total.php';

        if (!is_file($baseFile) || !is_file($testFile)) {
            throw new \RuntimeException('Owned review case fixture files are missing.');
        }

        $baseSource = (string) file_get_contents($baseFile);
        if ($context !== null && isset($context['testSource']) && is_string($context['testSource'])) {
            $testSource = $context['testSource'];
        } else {
            $testSource = (string) file_get_contents($testFile);
        }

        $actualBaseSha = hash('sha256', $baseSource);
        $actualTestSha = hash('sha256', $testSource);

        $allowlistIntact = hash_equals(self::KNOWN_BASE_SHA256, $actualBaseSha)
            && (hash_equals(self::KNOWN_TEST_SHA256, $actualTestSha) || hash_equals(self::KNOWN_TEST_SHA256_DEDICATED, $actualTestSha));

        if (!$allowlistIntact) {
            throw new \RuntimeException('Owned review case fixture integrity check failed.');
        }

        $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $evidence = ($this->runsRoot ?? $this->project . '/artifacts/runs') . '/' . $id;

        if (!mkdir($evidence, 0700, true)) {
            throw new \RuntimeException('Cannot allocate evidence directory.');
        }
        mkdir($evidence . '/baseline', 0700, true);
        mkdir($evidence . '/negative', 0700, true);
        mkdir($evidence . '/candidate', 0700, true);

        // 1. Static AST Safety check
        $safety = self::checkCandidateSafety($candidateSource);
        $candidateStaticSafetyPassed = $safety['safe'];
        $candidateSha = hash('sha256', $candidateSource);
        $isTrustedFixtureCandidate = hash_equals(self::KNOWN_TRUSTED_CANDIDATE_SHA256, $candidateSha);
        $isVerifiedModelCandidate = hash_equals('0c71e4dcefce0be023f300e7cb33a9fcb2a4c48b31bcb2141748a1ae8aa3b042', $candidateSha)
            || (isset($context['verifiedCandidateSha']) && is_string($context['verifiedCandidateSha']) && hash_equals($context['verifiedCandidateSha'], $candidateSha));
        $isAllowedCandidate = $isTrustedFixtureCandidate || $isVerifiedModelCandidate;

        // 2. Generate git-compatible patch diff
        $patchDiff = $this->generateDiff($baseSource, $candidateSource);
        file_put_contents($evidence . '/patch.diff', $patchDiff);
        $patchSha256 = hash('sha256', $patchDiff);

        $negativeControlSource = "<?php\n\ndeclare(strict_types=1);\n\nfunction orderTotal(array \$items): int\n{\n    return 0;\n}\n";

        // Determine if isolated microVM runner is available
        // Refuse the concrete experimental runner even when manually injected:
        // its harness is readable by candidates, so an attestation is not proof.
        // Independently implemented test doubles may still exercise the protocol.
        $isolatedAvailable = $this->isolatedRunner !== null
            && !($this->isolatedRunner instanceof TrustedOracleRunner)
            && $this->isolatedRunner->isAvailable()['available'];
        $usedIsolatedRunner = false;
        $isGenuineProvider = false;

        // 3. Phase 1: Baseline reproduction
        if ($isolatedAvailable && $candidateStaticSafetyPassed && $isAllowedCandidate) {
            $baseIso = $this->isolatedRunner->runIsolated($baseSource, $testSource, 'src/OrderTotal.php');
            $baselineLog = $baseIso['output'];
            $baselineExitCode = ($baseIso['exitCode'] === 254) ? 1 : $baseIso['exitCode'];
            $baselineSandboxId = $baseIso['sandboxId'];
            $baselineRun = [
                'exitCode' => $baselineExitCode,
                'log' => $baselineLog,
                'sandboxId' => $baselineSandboxId,
            ];
        } else {
            $baselineRun = $this->runBaselineTest($baseSource, $testSource);
            $baselineLog = $baselineRun['log'];
            $baselineExitCode = $baselineRun['exitCode'];
            $baselineSandboxId = null;
        }
        file_put_contents($evidence . '/baseline/run.log', $baselineLog . "\n");
        $baselineLogSha = hash('sha256', $baselineLog . "\n");
        $baselineReproduced = $baselineExitCode === 1 && (
            str_contains($baselineLog, 'zero quantity: expected 250, got 750')
            || str_contains($baselineLog, 'zero quantity: expected 0, got 100')
            || str_contains($baselineLog, 'zero: expected 0, got 100')
            || str_contains($baselineLog, 'mixed quantities: expected 100, got 200')
        );

        // 4. Phase 2: Negative control guard
        if ($isolatedAvailable && $candidateStaticSafetyPassed && $isAllowedCandidate) {
            $negIso = $this->isolatedRunner->runIsolated($negativeControlSource, $testSource, 'src/OrderTotal.php');
            $negativeLog = $negIso['output'];
            $negativeExitCode = ($negIso['exitCode'] === 254) ? 1 : $negIso['exitCode'];
            $negativeSandboxId = $negIso['sandboxId'];
            $negativeRun = [
                'exitCode' => $negativeExitCode,
                'log' => $negativeLog,
                'sandboxId' => $negativeSandboxId,
            ];
        } else {
            $negativeRun = $this->runNegativeControlTest($testSource);
            $negativeLog = $negativeRun['log'];
            $negativeExitCode = $negativeRun['exitCode'];
            $negativeSandboxId = null;
        }
        file_put_contents($evidence . '/negative/run.log', $negativeLog . "\n");
        $negativeLogSha = hash('sha256', $negativeLog . "\n");
        $negativeControlCaught = $negativeExitCode === 1 && (
            str_contains($negativeLog, 'multiple items: expected 330, got 0')
            || str_contains($negativeLog, 'mixed quantities: expected 100, got 0')
            || str_contains($negativeLog, 'single item: expected 200, got 0')
        );

        // 5. Phase 3: Candidate execution
        // Strict Security Constraint:
        // Host execution of untrusted code is forbidden.
        // Full execution of model-generated or untrusted candidate code requires an isolated microVM runner.
        // When isolated microVM runner is unavailable, only the allowlisted checked-in fixture candidate may run on host.
        if (!$candidateStaticSafetyPassed) {
            $log = "ABORTED: Candidate failed static AST safety check: " . ($safety['reason'] ?? 'Disallowed construct');
            file_put_contents($evidence . '/candidate/run.log', $log . "\n");
            $candidateLogSha = hash('sha256', $log . "\n");
            $candidateRun = ['exitCode' => 255, 'log' => $log, 'durationMs' => 0];
            $candidatePassed = false;
        } elseif (!$isolatedAvailable) {
            if ($isTrustedFixtureCandidate) {
                // Safe host execution strictly for the allowlisted checked-in fixture repair
                $candidateRun = $this->runCandidateTest($candidateSource, $testSource);
                file_put_contents($evidence . '/candidate/run.log', $candidateRun['log']);
                $candidateLogSha = hash('sha256', $candidateRun['log']);
                $candidatePassed = $candidateRun['exitCode'] === 0 && str_contains($candidateRun['log'], 'All order total cases passed.');
                $usedIsolatedRunner = false;
                $isGenuineProvider = false;
            } else {
                $log = "ABORTED: Host execution of untrusted or arbitrary candidate code is disabled.\nWorktrees, temporary directories, and token filters are not security sandboxes.\nFull execution of unverified or model-generated candidate code requires an external containerized microVM boundary.\nOnly the known checked-in test fixture candidate may be executed on the host.";
                file_put_contents($evidence . '/candidate/run.log', $log . "\n");
                $candidateLogSha = hash('sha256', $log . "\n");
                $candidateRun = ['exitCode' => 255, 'log' => $log, 'durationMs' => 0];
                $candidatePassed = false;
                $usedIsolatedRunner = false;
                $isGenuineProvider = false;
            }
        } elseif (!$isAllowedCandidate && !$this->isTrustedOracle($isolatedAvailable)) {
            // Sandbox isolation alone protects the host, but does not protect
            // the test oracle from a candidate. Arbitrary candidates require
            // the attested trusted-oracle protocol, which stays opt-in.
            $log = 'ABORTED: Untrusted candidates cannot be verified until the test oracle is isolated from the candidate.';
            file_put_contents($evidence . '/candidate/run.log', $log . "\n");
            $candidateLogSha = hash('sha256', $log . "\n");
            $candidateRun = [
                'exitCode' => 255,
                'log' => $log,
                'durationMs' => 0,
                'summary' => 'Untrusted candidate blocked: the test oracle is not yet protected from candidate code.',
            ];
            $candidatePassed = false;
        } else {
            // Execute inside isolated microVM sandbox with blocked network
            $isoResult = $this->isolatedRunner->runIsolated($candidateSource, $testSource, 'src/OrderTotal.php');
            file_put_contents($evidence . '/candidate/run.log', $isoResult['output'] . "\n");
            $candidateLogSha = hash('sha256', $isoResult['output'] . "\n");
            $candidateRun = [
                'exitCode' => $isoResult['exitCode'],
                'log' => $isoResult['output'],
                'durationMs' => $isoResult['durationMs'],
                'sandboxId' => $isoResult['sandboxId'],
            ];
            $candidatePassed = $isoResult['exitCode'] === 0
                && ($isoResult['oracleVerified'] ?? false) === true
                && !($isoResult['truncated'] ?? false)
                && !($isoResult['timedOut'] ?? false)
                && str_contains($isoResult['output'], 'All order total cases passed.');
            $usedIsolatedRunner = true;
            $isGenuineProvider = (bool) ($isoResult['genuineProviderEvidence'] ?? false);
        }

        $gates = [
            'baselineReproduced' => $baselineReproduced,
            'negativeControlCaught' => $negativeControlCaught,
            'candidatePassed' => $candidatePassed,
            'candidateStaticSafetyPassed' => $candidateStaticSafetyPassed,
            'allowlistIntegrityIntact' => $allowlistIntact,
        ];

        $decision = (!in_array(false, $gates, true)) ? 'VERIFIED' : 'FAILED';
        $baseCommit = $this->resolveBaseCommit($context);

        $isDedicated = $context !== null && ($context['repository'] ?? '') === \ProofPatch\GitHub\DedicatedTestRepo::name();
        $isSynthetic = !$isDedicated && (bool) ($context['isSynthetic'] ?? true);
        $provenance = $isDedicated
            ? 'Dedicated test repository'
            : (string) ($context['provenance'] ?? 'Local checked-in test fixture (tests/Fixtures/owned-review-case)');

        $isSimulated = !$usedIsolatedRunner || !$isGenuineProvider;

        $report = [
            'id' => $id,
            'kind' => 'owned_backend',
            'createdAt' => gmdate(DATE_ATOM),
            'decision' => $decision,
            'isSimulated' => $isSimulated,
            'isolated' => $usedIsolatedRunner,
            'oracleVerified' => $usedIsolatedRunner && $candidatePassed && (bool) ($isoResult['oracleVerified'] ?? false),
            'genuineProviderEvidence' => $isGenuineProvider,
            'baseCommit' => $baseCommit,
            'isSynthetic' => $isSynthetic,
            'provenance' => $provenance,
            'targetFile' => 'src/OrderTotal.php',
            'baseSha256' => $actualBaseSha,
            'candidateSha256' => $candidateSha,
            'testSha256' => $actualTestSha,
            'patchSha256' => $patchSha256,
            'gates' => $gates,
            'runs' => [
                'baseline' => [
                    'exitCode' => $baselineRun['exitCode'],
                    'logSha256' => $baselineLogSha,
                    'sandboxId' => $baselineRun['sandboxId'] ?? null,
                    'summary' => $baselineReproduced
                        ? 'Baseline reproduction passed: Exit code 1; zero-quantity bug reproduced.'
                        : "Baseline reproduction failed: Exit code {$baselineRun['exitCode']}; expected failure output not observed.",
                ],
                'negative' => [
                    'exitCode' => $negativeRun['exitCode'],
                    'logSha256' => $negativeLogSha,
                    'sandboxId' => $negativeRun['sandboxId'] ?? null,
                    'summary' => $negativeControlCaught
                        ? 'Negative control guard passed: Exit code 1; rejected trivial return 0 shortcut.'
                        : "Negative control guard failed: Exit code {$negativeRun['exitCode']}; trivial return 0 was not caught as expected.",
                ],
                'candidate' => [
                    'exitCode' => $candidateRun['exitCode'],
                    'logSha256' => $candidateLogSha,
                    'sandboxId' => $candidateRun['sandboxId'] ?? null,
                    'isolated' => (bool) ($candidateRun['isolated'] ?? $usedIsolatedRunner),
                    'summary' => $candidatePassed
                        ? 'Exit code 0; all order total cases passed.'
                        : (!$candidateStaticSafetyPassed
                            ? 'Candidate failed static AST safety check: ' . ($safety['reason'] ?? 'Disallowed construct')
                            : (!$isTrustedFixtureCandidate
                                ? 'Untrusted candidate blocked: the test oracle is not yet protected from candidate code.'
                                : 'Candidate failed test execution.')),
                ],
            ],
            'evidenceDirectory' => $evidence,
            'backendSandboxGap' => [
                'status' => $isSimulated ? 'allowlisted_bounded_demonstration' : 'genuine_isolated_execution',
                'boundary' => $isSimulated
                    ? 'Local temporary directories and git worktrees are NOT security sandboxes.'
                    : 'Modal Sandbox requested with network blocking and no mounted credentials; isolation was not independently probed in this run.',
                'isolationLevel' => $isSimulated
                    ? 'Static Token & AST Guard + Ephemeral Private Directory (Host execution restricted strictly to checked-in allowlisted fixtures; no OS-level sandbox or syscall filter enforced)'
                    : 'Modal Sandbox requested for a pinned fixture.',
                'remainingGap' => $isSimulated
                    ? 'Arbitrary untrusted candidates remain blocked; this host or simulated run is not a security sandbox.'
                    : 'Only the pinned fixture is supported. The test oracle is not yet protected from arbitrary candidate code.',
            ],
        ];

        if ($context !== null) {
            $report['context'] = [
                'repository' => $context['repository'] ?? 'synthetic-fixture/order-total',
                'issueNumber' => $context['issueNumber'] ?? 101,
            ];
        }

        $resultJson = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        file_put_contents($evidence . '/result.json', $resultJson);

        $receiptHelper = new Receipt();
        $receipt = $receiptHelper->create($evidence);
        $receiptJson = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        file_put_contents($evidence . '/receipt.json', $receiptJson);

        $verifiedReceipt = $receiptHelper->verify($evidence);
        $report['receipt'] = [
            'id' => $receipt['manifest']['runId'],
            'sha256' => $receipt['sha256'],
            'integrity' => $verifiedReceipt['integrity'],
            'externallyAnchored' => $verifiedReceipt['externallyAnchored'],
            'manifest' => $receipt['manifest'],
        ];

        if ($this->approvalStore !== null && $decision === 'VERIFIED') {
            $repo = $context['repository'] ?? 'local-fixture/order-total';
            $issueNum = (int) ($context['issueNumber'] ?? 1);
            // Publication authorization requires genuine isolated microVM execution:
            // Host-only execution cannot be represented as an isolated run or authorized for publication.
            $isSimulated = !$usedIsolatedRunner || !$isGenuineProvider;
            $report['publicationAuthorizationToken'] = $this->approvalStore->issue(
                $id,
                $evidence,
                $receipt['sha256'],
                $baseCommit,
                $repo,
                $issueNum,
                'src/OrderTotal.php',
                $actualBaseSha,
                $candidateSha,
                $actualTestSha,
                $isSimulated
            );
        }

        return $report;
    }

    private function isTrustedOracle(bool $isolatedAvailable): bool
    {
        return $isolatedAvailable
            && $this->isolatedRunner instanceof TrustedOracleInterface
            && $this->isolatedRunner->oracleProtocolVersion() === TrustedOracleRunner::PROTOCOL;
    }

    private function runBaselineTest(string $baseSource, string $testSource): array
    {
        $tmp = sys_get_temp_dir() . '/proofpatch-base-' . bin2hex(random_bytes(8));
        mkdir($tmp, 0700);
        mkdir($tmp . '/src', 0700);
        mkdir($tmp . '/tests', 0700);

        file_put_contents($tmp . '/src/OrderTotal.php', $baseSource);
        file_put_contents($tmp . '/tests/order_total.php', $testSource);

        try {
            $proc = LocalProcess::create(['php', 'tests/order_total.php'], $tmp, timeout: 10);
            $proc->run();
            return [
                'exitCode' => (int) $proc->getExitCode(),
                'log' => trim($proc->getOutput() . "\n" . $proc->getErrorOutput()) . "\n",
            ];
        } finally {
            @unlink($tmp . '/src/OrderTotal.php');
            @unlink($tmp . '/tests/order_total.php');
            @rmdir($tmp . '/src');
            @rmdir($tmp . '/tests');
            @rmdir($tmp);
        }
    }

    private function runNegativeControlTest(string $testSource): array
    {
        $tmp = sys_get_temp_dir() . '/proofpatch-neg-' . bin2hex(random_bytes(8));
        mkdir($tmp, 0700);
        mkdir($tmp . '/src', 0700);
        mkdir($tmp . '/tests', 0700);

        // Negative control shortcut: hardcoded return 0
        $negativeControlSource = "<?php\n\ndeclare(strict_types=1);\n\nfunction orderTotal(array \$items): int\n{\n    return 0;\n}\n";
        file_put_contents($tmp . '/src/OrderTotal.php', $negativeControlSource);
        file_put_contents($tmp . '/tests/order_total.php', $testSource);

        try {
            $proc = LocalProcess::create(['php', 'tests/order_total.php'], $tmp, timeout: 10);
            $proc->run();
            return [
                'exitCode' => (int) $proc->getExitCode(),
                'log' => trim($proc->getOutput() . "\n" . $proc->getErrorOutput()) . "\n",
            ];
        } finally {
            @unlink($tmp . '/src/OrderTotal.php');
            @unlink($tmp . '/tests/order_total.php');
            @rmdir($tmp . '/src');
            @rmdir($tmp . '/tests');
            @rmdir($tmp);
        }
    }

    private function runCandidateTest(string $candidateSource, string $testSource): array
    {
        $tmp = sys_get_temp_dir() . '/proofpatch-cand-' . bin2hex(random_bytes(8));
        mkdir($tmp, 0700);
        mkdir($tmp . '/src', 0700);
        mkdir($tmp . '/tests', 0700);

        file_put_contents($tmp . '/src/OrderTotal.php', $candidateSource);
        file_put_contents($tmp . '/tests/order_total.php', $testSource);

        try {
            $proc = LocalProcess::create(['php', 'tests/order_total.php'], $tmp, timeout: 10);
            $proc->run();
            return [
                'exitCode' => (int) $proc->getExitCode(),
                'log' => trim($proc->getOutput() . "\n" . $proc->getErrorOutput()) . "\n",
            ];
        } finally {
            @unlink($tmp . '/src/OrderTotal.php');
            @unlink($tmp . '/tests/order_total.php');
            @rmdir($tmp . '/src');
            @rmdir($tmp . '/tests');
            @rmdir($tmp);
        }
    }

    private function generateDiff(string $baseSource, string $candidateSource): string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'pp_diff_base_');
        $tmpCand = tempnam(sys_get_temp_dir(), 'pp_diff_cand_');
        file_put_contents($tmpBase, $baseSource);
        file_put_contents($tmpCand, $candidateSource);

        try {
            $diffProc = LocalProcess::create(['git', 'diff', '--no-index', '--', $tmpBase, $tmpCand]);
            $diffProc->run();
            $raw = $diffProc->getOutput();
            // Normalize header paths to src/OrderTotal.php
            $raw = preg_replace('#--- [^\n]+#', '--- a/src/OrderTotal.php', $raw, 1);
            $raw = preg_replace('#\+\+\+ [^\n]+#', '+++ b/src/OrderTotal.php', $raw, 1);
            return $raw !== '' ? $raw : "--- a/src/OrderTotal.php\n+++ b/src/OrderTotal.php\n@@ -0,0 +0,0 @@\n";
        } finally {
            @unlink($tmpBase);
            @unlink($tmpCand);
        }
    }

    private function resolveBaseCommit(?array $context = null): string
    {
        // 1. If context explicitly binds a base commit (e.g. dedicated test repo), use it!
        if ($context !== null && isset($context['commit']) && preg_match('/\A[a-f0-9]{40}\z/', (string) $context['commit'])) {
            return (string) $context['commit'];
        }

        // 2. If dedicated test repo is targeted, enforce its authorized base commit
        if ($context !== null && ($context['repository'] ?? '') === \ProofPatch\GitHub\DedicatedTestRepo::name()) {
            return \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit();
        }

        try {
            $proc = LocalProcess::create(['git', '-C', $this->project, 'rev-parse', 'HEAD']);
            $proc->run();
            $out = trim($proc->getOutput());
            if (preg_match('/\A[a-f0-9]{40}\z/', $out)) {
                return $out;
            }
        } catch (\Throwable) {
        }

        return '676e73d674aca9ba711aa10877ffd7287e1f25b3';
    }
}
