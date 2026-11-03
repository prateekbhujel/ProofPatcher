<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\Support\KernelCli;
use ProofPatch\Support\TerminalDashboard;

/**
 * Interactive kernel shell: the terminal OS menu. Driven by the php-terminal
 * extension (raw single-key menu) for an interactive terminal experience.
 * Thin by design — every action delegates to a directly-runnable,
 * unit-tested command, so scripts and tests never need this menu.
 */
final class KernelShellCommand extends Command
{
    protected $signature = 'kernel';

    protected $description = 'Open the interactive ProofPatcher Kernel shell';

    /** @return list<array{key: string, label: string, detail: string}> */
    private function menuOptions(): array
    {
        return [
            ['key' => 'status', 'label' => 'Status dashboard', 'detail' => 'Connection, provider and review status'],
            ['key' => 'connect', 'label' => 'Connect GitHub', 'detail' => 'Sign in with the GitHub device flow'],
            ['key' => 'select', 'label' => 'Select repository', 'detail' => 'Choose a repository in your GitHub grant'],
            ['key' => 'review', 'label' => 'Review a pull request', 'detail' => 'Request an advisory review'],
            ['key' => 'autopilot', 'label' => 'Run autopilot', 'detail' => 'Poll for public PR reviews for a limited time'],
            ['key' => 'findings', 'label' => 'Recent findings', 'detail' => 'See locally saved review results'],
            ['key' => 'telemetry', 'label' => 'Usage', 'detail' => 'View local usage estimates'],
            ['key' => 'verify', 'label' => 'Verify a patch', 'detail' => 'Run verification for a supported patch'],
            ['key' => 'mentions', 'label' => 'Listen for review requests', 'detail' => 'Watch public PR mentions while this runs'],
            ['key' => 'context', 'label' => 'Search local code', 'detail' => 'Find matching lines in local Git repositories'],
            ['key' => 'quit', 'label' => 'Quit', 'detail' => 'Close the terminal dashboard'],
        ];
    }

    public function handle(): int
    {
        // Symfony's isInteractive() is true unless -n is passed, even with a
        // closed stdin — which would spin the menu forever. Require a real tty.
        $stdinTty = function_exists('stream_isatty') && @stream_isatty(STDIN);
        $stdoutTty = function_exists('stream_isatty') && @stream_isatty(STDOUT);
        if (!$stdinTty || !$stdoutTty || !$this->input->isInteractive()) {
            $this->error('The kernel shell needs an interactive terminal. Run a direct command instead: kernel:status, github:connect, kernel:select-repo, kernel:review, kernel:autopilot, kernel:reviews, kernel:telemetry, review:verify.');
            return self::FAILURE;
        }

        $term = $this->openTerminal();
        if ($term === null) {
            $this->error(!extension_loaded('terminal') || !class_exists(\Io\Terminal\Terminal::class)
                ? 'ProofPatcher requires the PHP terminal extension. Install and enable it for your CLI PHP.'
                : 'The terminal extension could not open this terminal. Check TERM and try again.');
            return self::FAILURE;
        }

        foreach (KernelCli::bootLines() as $line) {
            $this->line($line);
        }
        $this->line('Extension terminal ' . $this->extensionVersion() . ' driving the menu.');
        $this->line('');

        $lastAction = null;
        while (true) {
            try {
                $state = KernelCli::connectState();
                $repository = $state['connected'] ? KernelCli::getSelectedRepo() : null;
                if ($repository !== null && !array_filter($state['repos'], static fn (array $repo): bool => strcasecmp($repo['fullName'], $repository) === 0)) {
                    $repository = null;
                }
                $status = [
                    'github: '.($state['connected'] ? 'sign-in saved (not checked)' : 'not connected'),
                    'repo: '.($repository ?? 'none'),
                    'hari: '.$this->hariSummary(),
                ];
                $action = $this->supportsAnsi($term)
                    ? TerminalDashboard::choose($term, $this->menuOptions(), $status, $lastAction)
                    : $this->plainChoice($this->menuOptions(), $status);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }

            if ($action === null || $action === 'quit') {
                $this->line('shell closed.');
                return self::SUCCESS;
            }

            $lastAction = $action;

            // Instant acknowledgement: the shell answers on this frame, before
            // the (possibly slow) action runs, so Enter never feels dead.
            $this->line('');
            $this->line('▸ ' . $this->labelFor($action) . ' …');
            match ($action) {
                'status' => $this->call('kernel:status'),
                'connect' => $this->call('github:connect'),
                'select' => $this->call('kernel:select-repo'),
                'review' => $this->reviewOnce(),
                'autopilot' => $this->autopilotOnce(),
                'findings' => $this->call('kernel:reviews'),
                'telemetry' => $this->call('kernel:telemetry'),
                'verify' => $this->verifyOnce(),
                'mentions' => $this->mentionsOnce(),
                'context' => $this->contextOnce(),
                default => null,
            };
            $this->line('');
            // The dashboard uses an alternate screen, so keep command output
            // visible until the user is ready to return.
            $this->promptLine('Press Enter to return');
        }
    }

    private function hariSummary(): string
    {
        try {
            $registry = \Hari\Hari::registry();
            return count($registry->abilities()) . ' abilities · ' . count($registry->skills()) . ' skills · ' . ($registry->isFrozen() ? 'frozen' : 'mutable');
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    private function openTerminal(): ?object
    {
        try {
            if (!extension_loaded('terminal') || !class_exists(\Io\Terminal\Terminal::class)) {
                return null;
            }
            $term = \Io\Terminal\Terminal::create();
            if (!$term->isTty()) {
                return null;
            }

            return $term;
        } catch (\Throwable) {
            return null;
        }
    }

    private function supportsAnsi(object $term): bool
    {
        try {
            return $term->supportsAnsi();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Plain input for NO_COLOR and terminals without ANSI support. */
    private function plainChoice(array $options, array $status): ?string
    {
        $this->line('ProofPatcher');
        foreach ($status as $line) {
            $this->line((string) preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $line));
        }
        foreach ($options as $index => $option) {
            $this->line(($index + 1).'. '.$option['label']);
        }
        while (true) {
            fwrite(STDOUT, 'Choose an action (number or q): ');
            $input = fgets(STDIN);
            if (!is_string($input) || strtolower(trim($input)) === 'q') return null;
            $input = trim($input);
            if (ctype_digit($input) && (int) $input >= 1 && (int) $input <= count($options)) {
                return $options[(int) $input - 1]['key'];
            }
            $this->line('Enter a listed number or q.');
        }
    }

    private function mentionsOnce(): void
    {
        $repo = KernelCli::getSelectedRepo();
        if ($repo === null) {
            $this->warn('Select a public repository first.');
            return;
        }
        $minutes = $this->promptLine('Listen for review requests (minutes, 1-180)', '10');
        if (!ctype_digit($minutes) || (int) $minutes < 1 || (int) $minutes > 180) {
            $this->warn('Enter 1 to 180.');
            return;
        }
        $answer = strtolower($this->promptLine('Post bot responses on GitHub? [y/N]', 'N'));
        $options = ['--repo' => $repo, '--minutes' => (int) $minutes];
        if ($answer === 'y' || $answer === 'yes') $options['--publish'] = true;
        $this->call('kernel:mentions', $options);
    }

    private function contextOnce(): void
    {
        $path = $this->promptLine('Local Git repository path');
        if ($path === '') return;
        $repositories = [$path];
        while (count($repositories) < 5) {
            $path = $this->promptLine('Another repository path (Enter to search)');
            if ($path === '') break;
            $repositories[] = $path;
        }
        $query = $this->promptLine('Find text');
        if ($query === '') return;
        $this->call('kernel:context', ['query' => $query, '--repo' => $repositories, '--content' => true]);
    }

    private function extensionVersion(): string
    {
        try {
            $version = phpversion('terminal');
        } catch (\Throwable) {
            $version = false;
        }

        return is_string($version) && $version !== '' ? 'v' . $version : '(unknown version)';
    }

    private function promptLine(string $label, string $default = ''): string
    {
        $hint = $default !== '' ? " [{$default}]" : '';
        fwrite(STDOUT, $label . $hint . ': ');
        $line = fgets(STDIN);
        if (!is_string($line)) {
            return '';
        }
        $answer = trim($line);

        return $answer === '' ? $default : $answer;
    }

    private function reviewOnce(): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $target = $this->promptLine('Pull request (owner/repo#123 or URL)');
            if ($target === '') {
                return;
            }
            if (KernelCli::parsePrTarget($target) === null) {
                $this->warn('Expected owner/repo#123 or a github.com pull URL.');
                continue;
            }
            $this->call('kernel:review', ['target' => $target]);
            return;
        }
    }

    private function labelFor(string $action): string
    {
        foreach ($this->menuOptions() as $option) {
            if ($option['key'] === $action) {
                return $option['label'];
            }
        }

        return $action;
    }

    private function autopilotOnce(): void
    {
        $repo = KernelCli::getSelectedRepo();
        if ($repo === null) {
            $this->warn('No repository selected.');
            $this->call('kernel:select-repo');
            $repo = KernelCli::getSelectedRepo();
            if ($repo === null) {
                return;
            }
        }
        // Preconditions first: never ask for a time budget we cannot spend.
        $authorized = [];
        foreach (KernelCli::connectState()['repos'] as $row) {
            $authorized[] = strtolower($row['fullName']);
        }
        $blocker = KernelCli::autopilotBlocker($repo, $authorized, $this->reviewEnabled($repo));
        if ($blocker !== null && str_contains($blocker['why'], 'auto-review is off')) {
            // The one-click fix lives here, not in another terminal session.
            $answer = strtolower($this->promptLine("Auto-review is off for {$repo}. Enable now? [Y/n]", 'Y'));
            if ($answer === '' || $answer === 'y' || $answer === 'yes') {
                $this->call('kernel:select-repo', ['repo' => $repo, '--enable-review' => true]);
                $blocker = KernelCli::autopilotBlocker($repo, $authorized, $this->reviewEnabled($repo));
            }
        }
        if ($blocker !== null) {
            $this->error(KernelCli::report('kernel shell · autopilot', $blocker['why'], $blocker['fix']));
            return;
        }
        $minutes = $this->promptLine("Autopilot time budget for {$repo} (minutes, 1-180)", '10');
        if ($minutes === '' || (int) $minutes < 1 || (int) $minutes > 180) {
            $this->warn('Enter 1 to 180.');
            return;
        }
        $this->call('kernel:autopilot', ['--repo' => $repo, '--minutes' => (int) $minutes]);
    }

    private function reviewEnabled(string $repo): bool
    {
        try {
            return \ProofPatch\Models\RepositoryAutoReviewSetting::isRepoEnabled($repo);
        } catch (\Throwable) {
            return false;
        }
    }

    private function verifyOnce(): void
    {
        $path = $this->promptLine('Patch file path (JSON, max 16 KB)');
        if ($path === '') {
            return;
        }
        if (!is_file($path)) {
            $this->error(KernelCli::report('kernel shell · verify', "file not found: {$path}", 'pass the path to a JSON patch file (max 16 KB)'));
            return;
        }
        $this->call('review:verify', ['patch' => $path]);
    }
}
