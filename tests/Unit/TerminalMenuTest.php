<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Support\TerminalMenu;

final class TerminalMenuTest extends TestCase
{
    /** @return list<array{key: string, label: string}> */
    private function options(): array
    {
        return [
            ['key' => 'status', 'label' => 'Status dashboard'],
            ['key' => 'connect', 'label' => 'Connect GitHub'],
            ['key' => 'quit', 'label' => 'Quit'],
        ];
    }

    public function testNextIndexWrapsAround(): void
    {
        $this->assertSame(1, TerminalMenu::nextIndex(0, 3, 1));
        $this->assertSame(2, TerminalMenu::nextIndex(1, 3, 1));
        $this->assertSame(0, TerminalMenu::nextIndex(2, 3, 1));
        $this->assertSame(2, TerminalMenu::nextIndex(0, 3, -1));
        $this->assertSame(1, TerminalMenu::nextIndex(2, 3, -1));
        $this->assertSame(0, TerminalMenu::nextIndex(0, 1, 1));
        $this->assertSame(0, TerminalMenu::nextIndex(0, 0, 1));
    }

    public function testFromDigitMapsOneBasedDigits(): void
    {
        $this->assertSame(0, TerminalMenu::fromDigit('1', 3));
        $this->assertSame(2, TerminalMenu::fromDigit('3', 3));
        $this->assertNull(TerminalMenu::fromDigit('4', 3));
        $this->assertNull(TerminalMenu::fromDigit('0', 3));
        $this->assertNull(TerminalMenu::fromDigit('q', 3));
        $this->assertNull(TerminalMenu::fromDigit('', 3));
        $this->assertNull(TerminalMenu::fromDigit('12', 12));
    }

    public function testRenderHighlightsOneRow(): void
    {
        $frame = TerminalMenu::render($this->options(), 1);

        $this->assertStringContainsString('1. Status dashboard', $frame);
        $this->assertStringContainsString('2. Connect GitHub', $frame);
        $this->assertStringContainsString('3. Quit', $frame);
        // Exactly one highlight marker, on the Connect GitHub row.
        $this->assertSame(1, substr_count($frame, '❯'));
        $highlighted = explode("\n", $frame)[1] ?? '';
        $this->assertStringContainsString('❯', $highlighted);
        $this->assertStringContainsString('Connect GitHub', $highlighted);
    }

    public function testRenderClampsHighlight(): void
    {
        $frame = TerminalMenu::render($this->options(), 99);

        $this->assertSame(1, substr_count($frame, '❯'));
        $this->assertStringContainsString('Quit', explode("\n", $frame)[2] ?? '');
    }

    public function testRenderEmptyOptions(): void
    {
        $this->assertSame('', TerminalMenu::render([], 0));
    }

    public function testUpSequenceMovesGivenLines(): void
    {
        $this->assertSame("\e[3A", TerminalMenu::up(3));
        $this->assertSame('', TerminalMenu::up(0));
    }

    public function testFrameBatchesTheWholeRedraw(): void
    {
        $frame = TerminalMenu::frame($this->options(), 1);

        $this->assertStringStartsWith("\e[3A", $frame);
        $this->assertSame(3, substr_count($frame, "\e[K"));
        $this->assertSame(1, substr_count($frame, '❯'));
        $this->assertStringContainsString('Status dashboard', $frame);
        $this->assertStringContainsString('Connect GitHub', $frame);
        $this->assertStringContainsString('Quit', $frame);
        $this->assertSame('', TerminalMenu::frame([], 0));
    }

    private function scriptedTerm(array $keys): object
    {
        return new class($keys) {
            /** @var list<string> */
            public array $written = [];

            /** @param list<string> $keys */
            public function __construct(private array $keys) {}

            public function enableRawMode(): object
            {
                return new \stdClass();
            }

            public function restoreMode(mixed $token = null): void {}

            public function write(string $data): void
            {
                $this->written[] = $data;
            }

            public function readKey(): mixed
            {
                $next = array_shift($this->keys);
                if ($next === null) {
                    return 'q';
                }

                return match ($next) {
                    'up' => \Io\Terminal\Key::Up,
                    'down' => \Io\Terminal\Key::Down,
                    'enter' => \Io\Terminal\Key::Enter,
                    'esc' => \Io\Terminal\Key::Escape,
                    default => $next,
                };
            }
        };
    }

    private function requireTerminalExtension(): void
    {
        if (!extension_loaded('terminal') || !enum_exists(\Io\Terminal\Key::class)) {
            $this->markTestSkipped('php-terminal extension not installed.');
        }
    }

    public function testChooseFollowsArrowsAndEnter(): void
    {
        $this->requireTerminalExtension();
        $term = $this->scriptedTerm(['down', 'down', 'enter']);

        $choice = TerminalMenu::choose($term, 'Pick', $this->options());

        $this->assertSame('quit', $choice);
        $this->assertStringContainsString("\e[3A", implode('', $term->written));
    }

    public function testChooseRedrawsWithOneWritePerKeypress(): void
    {
        $this->requireTerminalExtension();
        $term = $this->scriptedTerm(['down', 'enter']);

        TerminalMenu::choose($term, 'Pick', $this->options());

        $redraws = array_filter($term->written, fn (string $chunk) => str_contains($chunk, "\e[3A"));
        $this->assertCount(1, $redraws);
    }

    public function testChooseWrapsUpwards(): void
    {
        $this->requireTerminalExtension();

        $choice = TerminalMenu::choose($this->scriptedTerm(['up', 'enter']), 'Pick', $this->options());

        $this->assertSame('quit', $choice);
    }

    public function testChooseJumpsOnDigit(): void
    {
        $this->requireTerminalExtension();

        $choice = TerminalMenu::choose($this->scriptedTerm(['2']), 'Pick', $this->options());

        $this->assertSame('connect', $choice);
    }

    public function testChooseSupportsVimKeys(): void
    {
        $this->requireTerminalExtension();

        $this->assertSame('connect', TerminalMenu::choose($this->scriptedTerm(['j', 'enter']), 'Pick', $this->options()));
        $this->assertSame('quit', TerminalMenu::choose($this->scriptedTerm(['k', 'enter']), 'Pick', $this->options()));
    }

    public function testChooseQuitsOnQEscapeAndCtrlC(): void
    {
        $this->requireTerminalExtension();

        $this->assertNull(TerminalMenu::choose($this->scriptedTerm(['q']), 'Pick', $this->options()));
        $this->assertNull(TerminalMenu::choose($this->scriptedTerm(['esc']), 'Pick', $this->options()));
        $this->assertNull(TerminalMenu::choose($this->scriptedTerm(["\x03"]), 'Pick', $this->options()));
    }
}
