<?php

declare(strict_types=1);

namespace ProofPatch\Verifier;

use ProofPatch\Evidence\CanonicalJson;
use ProofPatch\Evidence\Receipt;
use ProofPatch\Patch\CssPatch;
use ProofPatch\Support\LocalProcess;
use ProofPatch\Support\RuntimePaths;

final readonly class CheckoutVerifier
{
    private const MASK = ['x' => 300, 'y' => 280, 'width' => 380, 'height' => 140];
    private const PROTECTED = ['tests/Locked/CheckoutLockedTest.php', 'app/Browser/CheckoutProbe.php'];

    public function __construct(private string $project, private ?string $runsRoot = null)
    {
        if (realpath($project) !== $project || trim($this->git('rev-parse', '--show-toplevel')) !== $project) {
            throw new \InvalidArgumentException('Expected the real project Git root.');
        }
    }

    public function verify(CssPatch $patch, ?array $proposalEvidence = null): array
    {
        $head = trim($this->git('rev-parse', 'HEAD'));
        $source = $this->git('show', $head.':'.$patch->file);
        $updated = $patch->apply($source);
        $authority = $this->protectedHashes($head);
        $this->assertRegularPath($this->project, $patch->file);
        if (!hash_equals(hash('sha256', $source), hash_file('sha256', $this->project.'/'.$patch->file))) {
            throw new \InvalidArgumentException('Checkout fixture has uncommitted changes.');
        }

        $id = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
        $evidence = ($this->runsRoot ?? RuntimePaths::runs($this->project)).'/'.$id;
        if (!mkdir($evidence, 0700, true)) {
            throw new \RuntimeException('Cannot allocate evidence directory.');
        }
        $temporary = sys_get_temp_dir().'/proofpatch-verify-'.bin2hex(random_bytes(8));
        if (!mkdir($temporary, 0700)) {
            throw new \RuntimeException('Cannot allocate worktree directory.');
        }
        $temporary = realpath($temporary);
        $proposal = $patch->toArray();
        $report = ['id' => $id, 'createdAt' => gmdate(DATE_ATOM), 'decision' => 'INCONCLUSIVE',
            'baseCommit' => $head, 'proposal' => $proposal,
            'proposalHashFormat' => 'proofpatch-json-v1',
            'proposalSha256' => hash('sha256', CanonicalJson::encode($proposal)),
            'protectedSha256' => $authority, 'allowedMask' => self::MASK, 'evidenceDirectory' => $evidence];
        if ($proposalEvidence !== null) {
            $report['proposalEvidence'] = $proposalEvidence;
        }
        $worktrees = [];
        try {
            foreach (['baseline', 'candidate'] as $case) {
                $path = $temporary.'/'.$case;
                $this->git('worktree', 'add', '--quiet', '--detach', $path, $head);
                $worktrees[$case] = $path;
                $this->assertRegularPath($path, $patch->file);
                if ($case === 'candidate') {
                    if (file_put_contents($path.'/'.$patch->file, $updated) !== strlen($updated)) {
                        throw new \RuntimeException('Could not write the candidate.');
                    }
                    $diff = LocalProcess::output(['git', '-C', $path, 'diff', '--', $patch->file]);
                    file_put_contents($evidence.'/patch.diff', $diff);
                    $report['patchSha256'] = hash('sha256', $diff);
                    LocalProcess::output(['git', '-C', $path, 'add', '--', $patch->file]);
                    $report['candidateTree'] = trim(LocalProcess::output(['git', '-C', $path, 'write-tree']));
                }
                $run = (new LockedCheckoutRunner($this->project))->run($path, $evidence.'/'.$case, $patch->file);
                $report['runs'][$case] = $run;
                if ($case === 'baseline' && !$this->reproduced($run)) {
                    $report['reason'] = 'BASELINE_NOT_REPRODUCED';
                    break;
                }
            }
            if (isset($report['runs']['candidate'])) {
                $candidate = $report['runs']['candidate'];
                if (!in_array($candidate['exitCode'], [0, 1], true)) {
                    throw new \RuntimeException('Candidate test execution was incomplete.');
                }
                $visual = (new PixelDiffGuard())->compare($evidence.'/baseline/checkout.png', $evidence.'/candidate/checkout.png', self::MASK);
                $intact = $authority === $this->protectedHashes($head)
                    && hash_equals(hash('sha256', $source), hash_file('sha256', $this->project.'/'.$patch->file));
                $report['visual'] = $visual;
                $report['gates'] = ['baselineReproduced' => true, 'protectedTestsPassed' => $candidate['exitCode'] === 0,
                    'outsideMaskPreserved' => $visual['passed'], 'authorityIntact' => $intact];
                $report['decision'] = !in_array(false, $report['gates'], true) ? 'VERIFIED' : 'FAILED';
            }
        } catch (\Throwable $error) {
            $report['decision'] = 'INCONCLUSIVE';
            $report['reason'] = $error->getMessage();
        } finally {
            foreach ($worktrees as $path) {
                try {
                    $this->git('worktree', 'remove', '--force', $path);
                } catch (\Throwable) {
                    $report['decision'] = 'INCONCLUSIVE';
                    $report['reason'] = 'WORKTREE_CLEANUP_FAILED';
                }
            }
            if (is_dir($temporary) && count(scandir($temporary)) === 2) {
                rmdir($temporary);
            }
        }
        $report['runnerSha256'] = hash_file('sha256', __FILE__);
        if (file_put_contents($evidence.'/result.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n") === false) {
            throw new \RuntimeException('Could not write the run report.');
        }
        $receipt = (new Receipt())->create($evidence);
        if (file_put_contents($evidence.'/receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n") === false) {
            throw new \RuntimeException('Could not write the evidence receipt.');
        }
        return $report;
    }

    private function reproduced(array $run): bool
    {
        $o = $run['observation'];
        return $run['exitCode'] === 1 && $o['initial'] === 'Standard' && $o['selected'] === 'Standard'
            && $o['pointer']['trusted'] === true && $o['pointer']['x'] === 420 && $o['pointer']['y'] === 320
            && $o['banner']['visible'] === true && $o['banner']['height'] === 48 && $o['neighborWorks'] === true
            && $o['viewport']['width'] === 1280 && $o['viewport']['height'] === 720 && $o['viewport']['scale'] == 1;
    }

    private function protectedHashes(string $head): array
    {
        $hashes = [];
        foreach (self::PROTECTED as $file) {
            $this->assertRegularPath($this->project, $file);
            $actual = hash_file('sha256', $this->project.'/'.$file);
            $headContent = $this->git('show', $head.':'.$file);
            if (!hash_equals(hash('sha256', $headContent), $actual)) {
                throw new \InvalidArgumentException('Protected source has uncommitted changes.');
            }
            $hashes[$file] = $actual;
        }
        return $hashes;
    }

    private function assertRegularPath(string $root, string $relative): void
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new \InvalidArgumentException('Symlinked source paths are not supported.');
            }
        }
        if (!is_file($path) || !str_starts_with(realpath($path), $root.'/')) {
            throw new \InvalidArgumentException('Source must be a regular file inside the project.');
        }
    }

    private function git(string ...$arguments): string
    {
        return LocalProcess::output(['git', '-C', $this->project, ...$arguments]);
    }
}
