<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/**
 * Instant keyboard menu driven by the php-terminal extension
 * (prateekbhujel/php-terminal): raw mode + single-key reads, with a minimal
 * line-rewrite redraw instead of full-screen repaints.
 *
 * The pure helpers (render/nextIndex/fromDigit/up) are unit-tested; choose()
 * needs a human tty.
 */
final class TerminalMenu
{
    /**
     * @param list<array{key: string, label: string}> $options
     */
    public static function render(array $options, int $highlight, ?int $width = null): string
    {
        if ($options === []) {
            return '';
        }
        $highlight = max(0, min(count($options) - 1, $highlight));
        $lines = [];
        foreach ($options as $index => $option) {
            $label = ($index + 1) . '. ' . $option['label'];
            $detail = $option['detail'] ?? '';
            if ($width !== null && $width >= 76 && is_string($detail) && $detail !== '') {
                $column = min(34, intdiv($width, 2));
                $label = mb_strimwidth($label, 0, $column - 2, '…', 'UTF-8');
                $label .= str_repeat(' ', max(2, $column - mb_strwidth($label, 'UTF-8'))) . $detail;
            }
            $row = self::fitRow($label, $width);
            $lines[] = $index === $highlight
                ? "\e[1m❯ {$row}\e[0m"
                : "  {$row}";
        }

        return implode("\n", $lines);
    }

    /**
     * Truncate a single menu row to the visible width (reserving the 2-cell
     * "❯ "/"  " gutter) so narrow terminals never wrap one option onto two
     * lines and desync the cursor-up redraw. Null/non-positive width disables
     * truncation and preserves legacy output byte-for-byte.
     */
    public static function fitRow(string $row, ?int $width): string
    {
        if ($width === null || $width <= 0) {
            return $row;
        }
        $max = $width - 2;
        if ($max < 2) {
            return $width >= 1 ? mb_substr('…', 0, $width) : '';
        }
        if (mb_strwidth($row, 'UTF-8') <= $max) {
            return $row;
        }

        return mb_strimwidth($row, 0, $max, '…', 'UTF-8');
    }

    public static function nextIndex(int $current, int $count, int $direction): int
    {
        if ($count <= 0) {
            return 0;
        }
        $next = ($current + $direction) % $count;

        return $next < 0 ? $next + $count : $next;
    }

    public static function fromDigit(string $key, int $count): ?int
    {
        if (!ctype_digit($key) || strlen($key) !== 1) {
            return null;
        }
        $number = (int) $key;
        if ($number < 1 || $number > $count) {
            return null;
        }

        return $number - 1;
    }

    public static function up(int $lines): string
    {
        return $lines > 0 ? "\e[{$lines}A" : '';
    }

    /** @param list<array{key: string, label: string}> $options */
    public static function dashboard(string $title, array $options, int $width, int $highlight = 0): string
    {
        $jump = count($options) > 1 ? '  ·  1–' . min(9, count($options)) . ' jump' : '';
        $hint = $width < 70 ? '↑↓ move · Enter open · q quit' : '↑↓ or j/k move  ·  Enter open' . $jump . '  ·  q quit';

        return "\e[35m" . self::fitRow('PROOFPATCHER  /  ' . $title, $width) . "\e[0m\n"
            . "\e[2m" . self::fitRow($hint, $width) . "\e[0m\n\n"
            . self::render($options, $highlight, $width);
    }

    /**
     * Runs the interactive menu. Returns the chosen option key, or null when
     * the user quits (q, Escape, or Ctrl+C).
     *
     * @param list<array{key: string, label: string}> $options
     */
    public static function choose(object $term, string $title, array $options, ?string $initialKey = null): ?string
    {
        if ($options === []) {
            return null;
        }
        $count = count($options);
        $highlight = 0;
        foreach ($options as $index => $option) {
            if ($option['key'] === $initialKey) {
                $highlight = $index;
                break;
            }
        }

        $token = $term->enableRawMode();
        if ($token === false) {
            throw new \RuntimeException('Could not enable terminal input. Try an interactive terminal.');
        }

        try {
            $width = TerminalWidth::columns();
            $term->write(self::dashboard($title, $options, $width, $highlight) . "\n");
            $term->write("\e[?25l");

            $emptyReads = 0;
            while (true) {
                $key = $term->readKey();
                if ($key === false) {
                    if (++$emptyReads >= 100) {
                        throw new \RuntimeException('Terminal input is unavailable.');
                    }
                    usleep(10000);
                    continue;
                }
                $emptyReads = 0;

                if ($key instanceof \Io\Terminal\Key) {
                    if ($key === \Io\Terminal\Key::Enter) {
                        $term->write("\n");
                        return $options[$highlight]['key'];
                    }
                    if ($key === \Io\Terminal\Key::Escape) {
                        $term->write("\n");
                        return null;
                    }
                    if ($key === \Io\Terminal\Key::Resize) {
                        $width = TerminalWidth::columns();
                        self::redraw($term, $options, $highlight, $width);
                        continue;
                    }
                    if ($key === \Io\Terminal\Key::Home || $key === \Io\Terminal\Key::End) {
                        $highlight = $key === \Io\Terminal\Key::Home ? 0 : $count - 1;
                        self::redraw($term, $options, $highlight, $width);
                        continue;
                    }
                    if ($key === \Io\Terminal\Key::Up) {
                        $highlight = self::nextIndex($highlight, $count, -1);
                        self::redraw($term, $options, $highlight, $width);
                    } elseif ($key === \Io\Terminal\Key::Down) {
                        $highlight = self::nextIndex($highlight, $count, 1);
                        self::redraw($term, $options, $highlight, $width);
                    }
                    continue;
                }

                $char = strtolower((string) $key);
                if ($char === 'q' || $char === "\x03") {
                    $term->write("\n");
                    return null;
                }
                if ($char === "\r" || $char === "\n") {
                    $term->write("\n");
                    return $options[$highlight]['key'];
                }
                if ($char === 'j') {
                    $highlight = self::nextIndex($highlight, $count, 1);
                    self::redraw($term, $options, $highlight, $width);
                } elseif ($char === 'k') {
                    $highlight = self::nextIndex($highlight, $count, -1);
                    self::redraw($term, $options, $highlight, $width);
                } else {
                    $digit = self::fromDigit($char, $count);
                    if ($digit !== null) {
                        $term->write("\n");
                        return $options[$digit]['key'];
                    }
                }
            }
        } finally {
            try {
                $term->write("\e[?25h");
            } finally {
                $term->restoreMode($token);
            }
        }
    }

    /**
     * The whole redraw as one string: cursor-up plus every cleared row. One
     * write per keypress keeps the menu flicker-free even on slow terminals.
     *
     * @param list<array{key: string, label: string}> $options
     */
    public static function frame(array $options, int $highlight, ?int $width = null): string
    {
        if ($options === []) {
            return '';
        }
        $out = self::up(count($options));
        foreach (explode("\n", self::render($options, $highlight, $width)) as $line) {
            $out .= "\e[K" . $line . "\n";
        }

        return $out;
    }

    /**
     * @param list<array{key: string, label: string}> $options
     */
    private static function redraw(object $term, array $options, int $highlight, ?int $width = null): void
    {
        $term->write(self::frame($options, $highlight, $width));
    }

}
