<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\Support\KernelCli;
use ProofPatch\Support\TerminalMenu;

/**
 * Interactive kernel shell: the terminal OS menu. Driven by the php-terminal
 * extension (raw single-key menu) with a cooked-mode numbered fallback, so
 * the shell stays instant and never depends on full-screen prompt redraws.
 * Thin by design — every action delegates to a directly-runnable,
 * unit-tested command, so scripts and tests never need this menu.
 */
final class KernelShellCommand extends Command
{
    protected $signature = 'kernel';

    protected $description = 'Open the interactive ProofPatcher Kernel shell';

    /** @return list<array{key: string, label: string}> */
    private function menuOptions(): array
    {
        return [
            ['key' => 'status', 'label' => 'Status dashboard'],
            ['key' => 'connect', 'label' => 'Connect GitHub (device flow)'],
            ['key' => 'select', 'label' => 'Select working repository'],
            ['key' => 'review', 'label' => 'Review one pull request'],
            ['key' => 'autopilot', 'label' => 'Run bounded autopilot'],
            ['key' => 'findings', 'label' => 'Recent review findings'],
            ['key' => 'telemetry', 'label' => 'Usage telemetry'],
            ['key' => 'verify', 'label' => 'Verify a patch file'],
            ['key' => 'quit', 'label' => 'Quit'],
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

        foreach (KernelCli::bootLines() as $line) {
            $this->line($line);
        }
        $this->line($term === null ? 'Extension missing: numbered menu mode.' : 'Extension terminal ' . $this->extensionVersion() . ' driving the menu.');
        $this->line('');

        while (true) {
            $action = $term === null
                ? TerminalMenu::choosePlain('What should the kernel do?', $this->menuOptions())
                : TerminalMenu::choose($term, 'What should the kernel do?', $this->menuOptions());

            if ($action === null || $action === 'quit') {
                $this->line('Kernel shell closed.');
                return self::SUCCESS;
            }

            $this->line('');
            match ($action) {
                'status' => $this->call('kernel:status'),
                'connect' => $this->call('github:connect'),
                'select' => $this->call('kernel:select-repo'),
                'review' => $this->reviewOnce(),
                'autopilot' => $this->autopilotOnce(),
                'findings' => $this->call('kernel:reviews'),
                'telemetry' => $this->call('kernel:telemetry'),
                'verify' => $this->verifyOnce(),
                default => null,
            };
            $this->line('');
        }
    }

    private function openTerminal(): ?object
    {
        try {
            if (!extension_loaded('terminal') || !class_exists(\Io\Terminal\Terminal::class)) {
                return null;
            }
            $term = \Io\Terminal\Terminal::create();
            if (!$term->isTty() || !$term->supportsAnsi()) {
                return null;
            }

            return $term;
        } catch (\Throwable) {
            return null;
        }
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
        $minutes = $this->promptLine("Autopilot time budget for {$repo} (minutes, 1-180)", '10');
        if ($minutes === '' || (int) $minutes < 1 || (int) $minutes > 180) {
            $this->warn('Enter 1 to 180.');
            return;
        }
        $this->call('kernel:autopilot', ['--repo' => $repo, '--minutes' => (int) $minutes]);
    }

    private function verifyOnce(): void
    {
        $path = $this->promptLine('Patch file path (JSON, max 16 KB)');
        if ($path === '') {
            return;
        }
        if (!is_file($path)) {
            $this->warn('File not found.');
            return;
        }
        $this->call('review:verify', ['patch' => $path]);
    }
}
