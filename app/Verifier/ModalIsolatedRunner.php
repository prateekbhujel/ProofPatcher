<?php

declare(strict_types=1);

namespace ProofPatch\Verifier;

/**
 * Executes PHP candidate and harness tests in an isolated Modal microVM sandbox via gVisor.
 *
 * Security & boundary constraints:
 * - Runtime is gVisor (`runtime='gvisor'`).
 * - Outbound and inbound network is explicitly blocked (`block_network=True`).
 * - Zero secrets, tokens, volumes, or host environment variables mounted.
 * - Resource bounded: CPU (0.25, 0.25), Memory (256, 256), strict timeout, 64KB output limit.
 * - Fresh ephemeral sandbox created per run, guaranteed termination with `sb.terminate(wait=True)` in finally.
 * - Non-blocking multiplexed I/O with parent timeout deadline to prevent subprocess pipe deadlock or hanging.
 * - Host control metadata is completely isolated from candidate stdout/stderr via an unmounted host control file.
 * - Prevents candidate output from spoofing exit codes or sandbox IDs.
 * - The local enablement flag defaults to false; it does not check Modal billing or credits.
 */
final class ModalIsolatedRunner implements IsolatedRunnerInterface
{
    public const MAX_OUTPUT_BYTES = 65536; // 64 KB limit
    public const DEFAULT_TIMEOUT_SECONDS = 30;
    public const MIN_TIMEOUT_SECONDS = 10; // Modal rejects sandbox timeouts below 10s
    public const MAX_TIMEOUT_SECONDS = 60;
    public const DEFAULT_PYTHON_VENV = null;

    // Verified base image with PHP CLI and Python 3.11 from independent smoke verification
    public const VERIFIED_PHP_IMAGE = 'php@sha256:d716009046f4acac161ed7a14921111398228c2311696f132686be8613cb19ab';

    /**
     * @param ?string $pythonPath Path to Python executable in modal virtualenv
     * @param bool $grantVerified Local opt-in for sandbox runs; does not verify a grant or balance
     * @param ?callable(string, string, string, int): array $sandboxExecutor Optional test simulation hook
     */
    public function __construct(
        private readonly ?string $pythonPath = null,
        private readonly bool $grantVerified = false,
        private readonly mixed $sandboxExecutor = null
    ) {
    }

    public function isAvailable(): array
    {
        if ($this->sandboxExecutor !== null) {
            return [
                'available' => true,
                'runner' => 'simulated',
                'isGenuineProvider' => false,
                'reason' => 'Simulated test runner active (mock executor). Not genuine Modal provider sandbox.',
            ];
        }

        $python = $this->resolvePythonBinary();
        if ($python === null) {
            return [
                'available' => false,
                'runner' => 'modal',
                'isGenuineProvider' => false,
                'reason' => 'Modal Python environment not found. Ensure python3 is available on system PATH or configure MODAL_PYTHON_PATH.',
            ];
        }

        // Legacy setting name: this is only a local opt-in, not a provider check.
        $sandboxEnabled = $this->grantVerified || (getenv('MODAL_GRANT_VERIFIED') === 'true');
        if (!$sandboxEnabled) {
            return [
                'available' => false,
                'runner' => 'modal',
                'isGenuineProvider' => false,
                'reason' => 'Sandbox runs are not enabled.',
            ];
        }

        return [
            'available' => true,
            'runner' => 'modal',
            'isGenuineProvider' => true,
            'reason' => 'Sandbox runs are enabled; connection and credit balance have not been checked.',
        ];
    }

    public function runIsolated(
        string $source,
        string $testHarness,
        string $relativePath = 'src/OrderTotal.php',
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
    ): array {
        $status = $this->isAvailable();
        if (!$status['available']) {
            throw new \RuntimeException("Modal isolated runner unavailable: {$status['reason']}");
        }

        $this->sanitizeRelativePath($relativePath);
        $timeoutSeconds = max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, $timeoutSeconds));
        $startTime = hrtime(true);

        // Simulation / test hook path: clearly labeled as simulated
        if ($this->sandboxExecutor !== null) {
            $executor = $this->sandboxExecutor;
            $res = $executor($source, $testHarness, $relativePath, $timeoutSeconds);
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $output = (string) ($res['output'] ?? '');
            $truncated = (bool) ($res['truncated'] ?? false);
            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                $output = substr($output, 0, self::MAX_OUTPUT_BYTES) . "\n[OUTPUT TRUNCATED: Exceeded 64KB limit]";
                $truncated = true;
            }

            $exitCode = (int) ($res['exitCode'] ?? 1);
            $timedOut = (bool) ($res['timedOut'] ?? false);
            $oracleVerified = (bool) ($res['oracleVerified'] ?? ($exitCode === 0 && !$truncated && !$timedOut));
            if (!$oracleVerified && $exitCode === 0) {
                $exitCode = 1;
            }

            return [
                'exitCode' => $exitCode,
                'output' => $output,
                'logSha256' => hash('sha256', $output),
                'durationMs' => $durationMs,
                'sandboxId' => (string) ($res['sandboxId'] ?? ('simulated-sb-' . bin2hex(random_bytes(6)))),
                'runnerType' => 'simulated-test-runner',
                'genuineProviderEvidence' => false,
                // A simulated callback runs in the host process, not a network sandbox.
                'networkBlocked' => false,
                'credentialsMounted' => false,
                'isolated' => false,
                'oracleVerified' => $oracleVerified,
                'truncated' => $truncated,
                'timedOut' => $timedOut,
            ];
        }

        // Genuine Modal microVM execution path
        $pythonBinary = $this->resolvePythonBinary();
        if ($pythonBinary === null) {
            throw new \RuntimeException('Cannot execute Modal runner: Python binary could not be resolved.');
        }

        $helperScript = $this->getHelperScriptPath();
        if (!is_file($helperScript)) {
            throw new \RuntimeException("Modal standalone runner helper script not found: {$helperScript}");
        }

        $spec = $this->generateModalRunnerSpec($source, $testHarness, $relativePath, $timeoutSeconds);
        $specFile = sys_get_temp_dir() . '/proofpatch-spec-' . bin2hex(random_bytes(8)) . '.json';
        $resultFile = sys_get_temp_dir() . '/proofpatch-result-' . bin2hex(random_bytes(8)) . '.json';

        try {
            file_put_contents($specFile, json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
            chmod($specFile, 0600);

            // Execute standalone helper passing spec file and private host result file
            $cmd = escapeshellcmd($pythonBinary) . ' ' . escapeshellarg($helperScript) . ' ' . escapeshellarg($specFile) . ' ' . escapeshellarg($resultFile);
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $env = [
                'MODAL_NO_BROWSER' => '1',
                'PYTHONUNBUFFERED' => '1',
            ];

            $process = proc_open($cmd, $descriptors, $pipes, sys_get_temp_dir(), $env);
            if (!is_resource($process)) {
                throw new \RuntimeException('Failed to spawn Modal runner subprocess.');
            }

            fclose($pipes[0]);

            $stdoutData = '';
            $stderrData = '';
            $parentDeadline = microtime(true) + $timeoutSeconds + 20; // 20s grace for gVisor VM provisioning

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $totalBytes = 0;
            $truncated = false;
            $timedOut = false;
            $observedExitCode = null;

            while (is_resource($process)) {
                $pstatus = proc_get_status($process);
                if (!$pstatus['running']) {
                    $observedExitCode = $pstatus['exitcode'];
                    // Drain both pipes with one shared cap, reading one extra byte to
                    // detect output that arrived just before the process exited.
                    foreach ([$pipes[1], $pipes[2]] as $stream) {
                        $remaining = max(0, self::MAX_OUTPUT_BYTES - $totalBytes);
                        $chunk = (string) stream_get_contents($stream, $remaining + 1);
                        if (strlen($chunk) > $remaining) {
                            $truncated = true;
                            $chunk = substr($chunk, 0, $remaining);
                        }
                        if ($stream === $pipes[1]) {
                            $stdoutData .= $chunk;
                        } else {
                            $stderrData .= $chunk;
                        }
                        $totalBytes += strlen($chunk);
                    }
                    break;
                }

                if (microtime(true) > $parentDeadline) {
                    proc_terminate($process, 9);
                    $timedOut = true;
                    $stderrData .= "\n[TIMEOUT: Subprocess parent deadline exceeded.]";
                    break;
                }

                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                $numChanged = @stream_select($read, $write, $except, 0, 100000);

                if ($numChanged > 0) {
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 4096);
                        if ($chunk !== false && strlen($chunk) > 0) {
                            if ($totalBytes + strlen($chunk) > self::MAX_OUTPUT_BYTES) {
                                $remaining = max(0, self::MAX_OUTPUT_BYTES - $totalBytes);
                                if ($remaining > 0) {
                                    $chunk = substr($chunk, 0, $remaining);
                                } else {
                                    $chunk = '';
                                }
                                $truncated = true;
                            }

                            if ($stream === $pipes[1]) {
                                $stdoutData .= $chunk;
                            } else {
                                $stderrData .= $chunk;
                            }
                            $totalBytes += strlen($chunk);
                        }
                    }

                    if ($truncated) {
                        proc_terminate($process, 9);
                        $stderrData .= "\n[OUTPUT TRUNCATED: Exceeded 64KB limit]";
                        break;
                    }
                }
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            $processExitCode = proc_close($process);
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            if ($timedOut) {
                throw new \RuntimeException('Modal microVM execution timed out.');
            }
            if ($truncated) {
                throw new \RuntimeException('Modal runner output exceeded the allowed limit.');
            }

            // Read verified private result channel from the host result file
            if (!is_file($resultFile)) {
                throw new \RuntimeException(
                    "Modal microVM execution failed: Subprocess exited with code {$processExitCode} without producing private result channel data. Stderr: {$stderrData}"
                );
            }

            // The helper's expected result contains at most bounded stdout and
            // stderr plus JSON encoding overhead; refuse an oversized file.
            $resultContent = file_get_contents($resultFile, false, null, 0, 524289);
            if ($resultContent === false || strlen($resultContent) > 524288) {
                throw new \RuntimeException('Modal runner result exceeds the allowed limit.');
            }
            $resultData = json_decode($resultContent, true);
            if (!is_array($resultData) || !is_int($resultData['exit_code'] ?? null)
                || !is_string($resultData['sandbox_id'] ?? null) || $resultData['sandbox_id'] === ''
                || !is_bool($resultData['oracle_verified'] ?? null)
                || !is_bool($resultData['truncated'] ?? null) || !is_bool($resultData['timed_out'] ?? null)
                || !is_string($resultData['stdout'] ?? null) || !is_string($resultData['stderr'] ?? null)) {
                throw new \RuntimeException('Corrupted private result data returned by Modal runner.');
            }

            $sandboxExitCode = (int) $resultData['exit_code'];
            // Failed fixture runs legitimately return a non-zero code, but the
            // helper and its result must agree. A stale or incomplete result is
            // not evidence of a successful sandbox execution.
            $helperExitCode = $observedExitCode !== null && $observedExitCode >= 0
                ? $observedExitCode : $processExitCode;
            if ($helperExitCode < 0 || $helperExitCode !== $sandboxExitCode) {
                throw new \RuntimeException('Modal runner exit status does not match its result.');
            }
            $sandboxId = (string) $resultData['sandbox_id'];
            $oracleVerified = (bool) ($resultData['oracle_verified'] ?? false);
            $isTruncated = (bool) ($resultData['truncated'] ?? $truncated);
            $isTimedOut = (bool) ($resultData['timed_out'] ?? $timedOut);

            $combinedOutput = trim(($resultData['stdout'] ?? $stdoutData) . "\n" . ($resultData['stderr'] ?? $stderrData));
            if (strlen($combinedOutput) > self::MAX_OUTPUT_BYTES || $isTruncated) {
                $combinedOutput = substr($combinedOutput, 0, self::MAX_OUTPUT_BYTES) . "\n[OUTPUT TRUNCATED: Exceeded 64KB limit]";
                $isTruncated = true;
                $sandboxExitCode = 1;
                $oracleVerified = false;
            }

            if (!$oracleVerified && $sandboxExitCode === 0) {
                $sandboxExitCode = 1;
            }

            return [
                'exitCode' => $sandboxExitCode,
                'output' => $combinedOutput,
                'logSha256' => hash('sha256', $combinedOutput),
                'durationMs' => $durationMs,
                'sandboxId' => $sandboxId,
                'runnerType' => 'modal-microvm',
                'genuineProviderEvidence' => true,
                'networkBlocked' => true,
                'credentialsMounted' => false,
                'isolated' => true,
                'oracleVerified' => $oracleVerified,
                'truncated' => $isTruncated,
                'timedOut' => $isTimedOut,
            ];
        } finally {
            if (file_exists($specFile)) {
                @unlink($specFile);
            }
            if (file_exists($resultFile)) {
                @unlink($resultFile);
            }
        }
    }

    public function getHelperScriptPath(): string
    {
        return dirname(__DIR__, 2) . '/scripts/modal_sandbox_runner.py';
    }

    /**
     * Generates execution specification dictionary for the standalone Modal runner helper.
     *
     * @return array{
     *     source_b64: string,
     *     test_harness_b64: string,
     *     relative_path: string,
     *     timeout_seconds: int,
     *     image: string
     * }
     */
    public function generateModalRunnerSpec(
        string $source,
        string $testHarness,
        string $relativePath = 'src/OrderTotal.php',
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        string $harnessPath = 'tests/order_total.php'
    ): array {
        $this->sanitizeRelativePath($relativePath);
        $this->sanitizeRelativePath($harnessPath);
        return [
            'source_b64' => base64_encode($source),
            'test_harness_b64' => base64_encode($testHarness),
            'relative_path' => $relativePath,
            'test_harness_path' => $harnessPath,
            'oracle_token' => bin2hex(random_bytes(32)),
            'timeout_seconds' => max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, $timeoutSeconds)),
            'image' => self::VERIFIED_PHP_IMAGE,
        ];
    }

    /**
     * Returns the content of the standalone syntax-checked helper script.
     */
    public function generateModalRunnerScript(
        string $source,
        string $testHarness,
        string $relativePath,
        int $timeoutSeconds
    ): string {
        $helperPath = $this->getHelperScriptPath();
        if (is_file($helperPath)) {
            return (string) file_get_contents($helperPath);
        }
        throw new \RuntimeException("Helper script not found at {$helperPath}");
    }

    private function sanitizeRelativePath(string $path): void
    {
        if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('Path traversal or absolute paths are strictly prohibited in runner.');
        }
        if (!preg_match('~\A[a-zA-Z0-9_./-]+\z~', $path)) {
            throw new \InvalidArgumentException('Invalid characters in relative file path.');
        }
    }

    private function resolvePythonBinary(): ?string
    {
        if ($this->pythonPath !== null) {
            return (is_file($this->pythonPath) && is_executable($this->pythonPath)) ? $this->pythonPath : null;
        }

        $envPath = getenv('MODAL_PYTHON_PATH') ?: ($_ENV['MODAL_PYTHON_PATH'] ?? $_SERVER['MODAL_PYTHON_PATH'] ?? null);
        if ($envPath && is_file($envPath) && is_executable($envPath)) {
            return $envPath;
        }

        $home = getenv('HOME') ?: ($_ENV['HOME'] ?? $_SERVER['HOME'] ?? null);
        if ($home) {
            $defaultVenv = $home . '/.local/share/proofpatch/venv/bin/python';
            if (is_file($defaultVenv) && is_executable($defaultVenv)) {
                return $defaultVenv;
            }
        }

        if ($virtualEnv = getenv('VIRTUAL_ENV')) {
            $venvPy = $virtualEnv . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
            if (is_file($venvPy) && is_executable($venvPy)) {
                return $venvPy;
            }
        }

        $path = getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin:/opt/homebrew/bin';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = $dir . DIRECTORY_SEPARATOR . 'python3';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
