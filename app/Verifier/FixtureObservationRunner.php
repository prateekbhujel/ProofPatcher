<?php

declare(strict_types=1);

namespace ProofPatch\Verifier;

use Symfony\Component\Process\Process;

/** Finite observations for the pinned local order-total fixture only. */
final class FixtureObservationRunner
{
    private const HARNESS_SHA = 'b8079fb1e7731fc763f0b009a11c2786d01b52cce08f7431768a64e8a5ef2cbf';
    private const BASELINE_SHA = '54bc37fb2b005badcf309c5a7091ba6a2e704fc54c8eff9e9c7afee7e4054859';

    /** An injected process is a test double, never provider evidence. */
    public function __construct(private readonly ?string $pythonPath = null, private readonly mixed $testProcess = null) {}

    /**
     * Candidate code runs only in network-blocked provider sandboxes. A passing
     * result covers the fixture inputs, not general correctness or safe code.
     */
    public function observe(string $candidateSource): array
    {
        if ($candidateSource === '' || strlen($candidateSource) > 65536 || !preg_match('//u', $candidateSource)) {
            throw new \InvalidArgumentException('Candidate source is invalid.');
        }
        $python = $this->resolvePython();
        $script = dirname(__DIR__, 2).'/scripts/observe_fixture_candidate.py';
        if ($python === null || !is_file($script)) {
            throw new \RuntimeException('The Modal Python environment is not configured.');
        }
        $request = json_encode(['candidate' => $candidateSource], JSON_THROW_ON_ERROR);
        if ($this->testProcess !== null) {
            if (!is_callable($this->testProcess)) throw new \LogicException('Invalid test process.');
            $response = ($this->testProcess)($request);
            $output = is_array($response) ? ($response['output'] ?? '') : '';
            $success = is_array($response) && ($response['success'] ?? false) === true;
        } else {
            // The harness locates `php` via PATH; web-server contexts may not
            // inherit a PATH containing it, so always include PHP's own bin dir.
            $env = ['PATH' => PHP_BINDIR.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')];
            $process = new Process([$python, $script], dirname(__DIR__, 2), $env, $request, 540);
            $process->run();
            $output = $process->getOutput();
            $success = $process->isSuccessful();
        }
        if (!$success || !is_string($output) || strlen($output) > 4096) {
            throw new \RuntimeException('Fixture observation did not complete. Check the Modal connection.');
        }
        try {
            $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Fixture observation returned invalid data.');
        }
        $valid = is_array($result) && ($result['scope'] ?? null) === 'finite_local_fixture'
            && in_array($result['status'] ?? null, ['passed', 'failed'], true)
            && ($result['harnessSha256'] ?? null) === self::HARNESS_SHA
            && ($result['baselineSha256'] ?? null) === self::BASELINE_SHA
            && is_string($result['candidateSha256'] ?? null)
            && hash_equals(hash('sha256', $candidateSource), $result['candidateSha256'])
            && is_array($result['calls'] ?? null);
        foreach (['baseline', 'negative', 'candidate'] as $key) {
            $valid = $valid && in_array($result[$key] ?? null, ['fixture_passed', 'fixture_failed', 'aborted'], true)
                && is_int($result['calls'][$key] ?? null) && $result['calls'][$key] >= 0 && $result['calls'][$key] <= 16;
        }
        if ($valid && $result['status'] === 'passed') {
            $valid = $result['baseline'] === 'fixture_failed' && $result['negative'] === 'fixture_failed'
                && $result['candidate'] === 'fixture_passed'
                && $result['calls'] === ['baseline' => 3, 'negative' => 3, 'candidate' => 3];
        }
        if (!$valid) throw new \RuntimeException('Fixture observation failed its integrity checks.');
        // Callers must never treat an injected process as a genuine provider run.
        $result['provider'] = $this->testProcess === null ? 'modal' : 'simulated';
        return $result;
    }

    private function resolvePython(): ?string
    {
        $path = $this->pythonPath ?? (getenv('MODAL_PYTHON_PATH') ?: ($_ENV['MODAL_PYTHON_PATH'] ?? $_SERVER['MODAL_PYTHON_PATH'] ?? null));
        if ($path === null) {
            $home = getenv('HOME');
            $path = $home ? $home.'/.local/share/proofpatch/venv/bin/python' : null;
        }
        return is_string($path) && is_file($path) && is_executable($path) ? $path : null;
    }
}
