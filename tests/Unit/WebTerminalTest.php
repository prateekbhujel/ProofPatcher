<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Support\WebTerminal;

final class WebTerminalTest extends TestCase
{
    public function testHelpListsTheReadOnlyCommands(): void
    {
        $this->assertSame(['kind' => 'help'], WebTerminal::parse('help'));
        $this->assertSame(['kind' => 'help'], WebTerminal::parse('  HELP  '));
        $this->assertStringContainsString('status', WebTerminal::HELP);
        $this->assertStringContainsString('reviews', WebTerminal::HELP);
        $this->assertStringContainsString('telemetry', WebTerminal::HELP);
        $this->assertStringContainsString('repos', WebTerminal::HELP);
    }

    public function testStatusAcceptsOnlyJsonFlag(): void
    {
        $this->assertSame(
            ['kind' => 'run', 'command' => 'kernel:status', 'params' => []],
            WebTerminal::parse('status')
        );
        $this->assertSame(
            ['kind' => 'run', 'command' => 'kernel:status', 'params' => ['--json' => true]],
            WebTerminal::parse('status --json')
        );
        $parsed = WebTerminal::parse('status --force');
        $this->assertSame('error', $parsed['kind']);
        $this->assertStringContainsString('--force', $parsed['message']);
    }

    public function testReviewsValidatesEveryOption(): void
    {
        $this->assertSame(
            ['kind' => 'run', 'command' => 'kernel:reviews', 'params' => ['--limit' => '5', '--repository' => 'acme/repo', '--status' => 'failed']],
            WebTerminal::parse('reviews --limit 5 --repository acme/repo --status failed')
        );
        $this->assertSame(
            ['kind' => 'run', 'command' => 'kernel:reviews', 'params' => ['--limit' => '10']],
            WebTerminal::parse('reviews --limit=10')
        );

        foreach (['reviews --limit 0', 'reviews --limit 99', 'reviews --limit x'] as $bad) {
            $parsed = WebTerminal::parse($bad);
            $this->assertSame('error', $parsed['kind'], $bad);
            $this->assertStringContainsString('--limit', $parsed['message']);
        }
        $parsed = WebTerminal::parse('reviews --repository nope');
        $this->assertSame('error', $parsed['kind']);
        $parsed = WebTerminal::parse('reviews --status bogus');
        $this->assertSame('error', $parsed['kind']);
        $parsed = WebTerminal::parse('reviews --repo acme/repo');
        $this->assertSame('error', $parsed['kind']);
        $parsed = WebTerminal::parse('reviews --limit');
        $this->assertSame('error', $parsed['kind']);
    }

    public function testTelemetryAndReposTakeNoArguments(): void
    {
        $this->assertSame(
            ['kind' => 'run', 'command' => 'kernel:telemetry', 'params' => []],
            WebTerminal::parse('telemetry')
        );
        $this->assertSame(
            ['kind' => 'run', 'command' => 'kernel:select-repo', 'params' => ['--list' => true]],
            WebTerminal::parse('repos')
        );
        $parsed = WebTerminal::parse('telemetry now');
        $this->assertSame('error', $parsed['kind']);
        $this->assertStringContainsString("'telemetry' takes no arguments", $parsed['message']);
    }

    public function testDangerousCommandsNeverParse(): void
    {
        foreach (['kernel:autopilot', 'autopilot', 'review acme/repo#1', 'kernel:review', 'github:connect', 'rm -rf /', 'php artisan migrate'] as $input) {
            $parsed = WebTerminal::parse($input);
            $this->assertSame('error', $parsed['kind'], $input);
        }
    }

    public function testShellMetacharactersAreRejected(): void
    {
        foreach (['status; rm x', 'status | tee', 'status && whoami', 'status $(x)', 'status `x`', "status 'x'", 'status > /tmp/x', 'status --limit=1;2'] as $input) {
            $parsed = WebTerminal::parse($input);
            $this->assertSame('error', $parsed['kind'], $input);
            $this->assertStringContainsString('shell characters', $parsed['message']);
        }
    }

    public function testEmptyAndOversizedInput(): void
    {
        $parsed = WebTerminal::parse('   ');
        $this->assertSame('error', $parsed['kind']);
        $this->assertSame('', $parsed['message']);

        $parsed = WebTerminal::parse(str_repeat('a', WebTerminal::MAX_INPUT + 1));
        $this->assertSame('error', $parsed['kind']);
        $this->assertStringContainsString('too long', $parsed['message']);
    }

    public function testUnknownCommandPointsToHelp(): void
    {
        $parsed = WebTerminal::parse('frobnicate');
        $this->assertSame('error', $parsed['kind']);
        $this->assertStringContainsString('frobnicate', $parsed['message']);
        $this->assertStringContainsString('help', $parsed['message']);
    }
}
