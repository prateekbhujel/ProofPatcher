<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Support\TerminalDashboard;

final class TerminalDashboardTest extends TestCase
{
    private function options(): array
    {
        return [
            ['key' => 'status', 'label' => 'Status', 'detail' => 'Connection and provider'],
            ['key' => 'review', 'label' => 'Review a pull request', 'detail' => 'Advisory only'],
            ['key' => 'quit', 'label' => 'Quit', 'detail' => 'Close dashboard'],
        ];
    }

    private function terminal(array $keys, int $width = 80, int $height = 24): object
    {
        return new class($keys, $width, $height) {
            public string $output = '';
            public bool $restored = false;
            public function __construct(private array $keys, private int $width, private int $height) {}
            public function enableRawMode(): object { return new \stdClass(); }
            public function getSize(): object { return (object) ['cols' => $this->width, 'rows' => $this->height]; }
            public function write(string $output): void { $this->output .= $output; }
            public function restoreMode(mixed $token): void { $this->restored = true; }
            public function readKey(): mixed { return array_shift($this->keys) ?? 'q'; }
        };
    }

    public function testSearchOnlyShowsMatchingActions(): void
    {
        $this->assertSame(['review'], array_column(TerminalDashboard::matches($this->options(), 'PULL'), 'key'));
        $this->assertSame([], TerminalDashboard::matches($this->options(), 'merge'));
    }

    public function testSearchSelectsMatchingActionAndRestoresTerminal(): void
    {
        if (!extension_loaded('terminal')) $this->markTestSkipped('terminal extension unavailable');
        $term = $this->terminal(['/', 'r', 'e', 'v', \Io\Terminal\Key::Enter]);
        $this->assertSame('review', TerminalDashboard::choose($term, $this->options(), ['GitHub: not connected']));
        $this->assertTrue($term->restored);
        $this->assertStringContainsString("\e[?1049h", $term->output);
        $this->assertStringContainsString("\e[?25h\e[?1049l", $term->output);
    }

    public function testNumberShortcutsAndLastSelectionRemainAvailable(): void
    {
        if (!extension_loaded('terminal')) $this->markTestSkipped('terminal extension unavailable');
        $this->assertSame('review', TerminalDashboard::choose($this->terminal(['2']), $this->options()));
        $this->assertSame('review', TerminalDashboard::choose($this->terminal([\Io\Terminal\Key::Enter]), $this->options(), [], 'review'));
    }

    public function testEscapeClearsSearchBeforeQuitting(): void
    {
        if (!extension_loaded('terminal')) $this->markTestSkipped('terminal extension unavailable');
        $term = $this->terminal(['/', 'q', \Io\Terminal\Key::Escape, \Io\Terminal\Key::Down, \Io\Terminal\Key::Enter]);
        $this->assertSame('review', TerminalDashboard::choose($term, $this->options()));
        $this->assertTrue($term->restored);
    }

    public function testSearchDoesNotExecuteWhenNothingMatches(): void
    {
        if (!extension_loaded('terminal')) $this->markTestSkipped('terminal extension unavailable');
        $term = $this->terminal(['/', 'z', 'z', \Io\Terminal\Key::Enter, \Io\Terminal\Key::Escape, 'q']);
        $this->assertNull(TerminalDashboard::choose($term, $this->options()));
        $this->assertStringContainsString('No matching actions', $term->output);
    }

    public function testPaintRespectsSmallViewportAndStripsControlText(): void
    {
        $paint = TerminalDashboard::paint($this->options(), 2, '', false, ["GitHub:\e[31muser\nname", 'Repo: 界界界界'], 18, 8);
        $plain = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $paint) ?? $paint;
        $lines = explode("\r\n", $plain);
        $this->assertLessThanOrEqual(8, count($lines));
        foreach ($lines as $line) $this->assertLessThan(18, mb_strwidth($line, 'UTF-8'));
        $this->assertStringNotContainsString("\e[31m", $paint);
        $this->assertStringContainsString('3. Quit', $plain);
        $this->assertStringContainsString('1. Status', $plain);
        $small = TerminalDashboard::paint($this->options(), 2, '', false, ['one', 'two'], 18, 4);
        $this->assertStringContainsString('Quit', $small);
    }

    public function testMascotFitsWideDashboardAndIsHiddenInSmallViewports(): void
    {
        $wide = TerminalDashboard::paint($this->options(), 0, '', false, ['GitHub: connected'], 80, 24);
        $plain = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $wide) ?? $wide;
        $this->assertStringContainsString('.-^-.', $plain);
        $this->assertStringContainsString('|o.o|', $plain);
        $this->assertStringContainsString('GitHub: connected', $plain);
        foreach (explode("\r\n", $plain) as $line) $this->assertLessThan(80, mb_strwidth($line, 'UTF-8'));

        $narrow = TerminalDashboard::paint($this->options(), 0, '', false, [], 40, 24);
        $short = TerminalDashboard::paint($this->options(), 0, '', false, [], 80, 8);
        $this->assertStringNotContainsString('|o.o|', $narrow);
        $this->assertStringNotContainsString('|o.o|', $short);
    }

    public function testReadErrorRestoresTerminal(): void
    {
        $term = $this->terminal([new \stdClass()]);
        try {
            TerminalDashboard::choose($term, $this->options());
            $this->fail('Expected an invalid key to throw');
        } catch (\Error $e) {
            $this->assertTrue($term->restored);
            $this->assertStringContainsString("\e[?25h\e[?1049l", $term->output);
        }
    }
}
