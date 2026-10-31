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
    public static function render(array $options, int $highlight): string
    {
        if ($options === []) {
            return '';
        }
        $highlight = max(0, min(count($options) - 1, $highlight));
        $lines = [];
        foreach ($options as $index => $option) {
            $row = ($index + 1) . '. ' . $option['label'];
            $lines[] = $index === $highlight
                ? "\e[1m❯ {$row}\e[0m"
                : "  {$row}";
        }

        return implode("\n", $lines);
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

    /**
     * Runs the interactive menu. Returns the chosen option key, or null when
     * the user quits (q, Escape, or Ctrl+C).
     *
     * @param list<array{key: string, label: string}> $options
     */
    public static function choose(object $term, string $title, array $options): ?string
    {
        if ($options === []) {
            return null;
        }
        $count = count($options);
        $highlight = 0;

        $token = $term->enableRawMode();
        if ($token === false) {
            return self::choosePlain($title, $options);
        }

        try {
            $term->write($title . "  \e[2m(↑↓ move · enter select · 1-{$count} jump · q quit)\e[0m\n");
            $term->write(self::render($options, $highlight) . "\n");
            $term->write("\e[?25l");

            while (true) {
                $key = $term->readKey();
                if ($key === false) {
                    continue;
                }

                if ($key instanceof \Io\Terminal\Key) {
                    if ($key === \Io\Terminal\Key::Enter) {
                        $term->write("\n");
                        return $options[$highlight]['key'];
                    }
                    if ($key === \Io\Terminal\Key::Escape) {
                        $term->write("\n");
                        return null;
                    }
                    if ($key === \Io\Terminal\Key::Up) {
                        $highlight = self::nextIndex($highlight, $count, -1);
                        self::redraw($term, $options, $highlight);
                    } elseif ($key === \Io\Terminal\Key::Down) {
                        $highlight = self::nextIndex($highlight, $count, 1);
                        self::redraw($term, $options, $highlight);
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
                    self::redraw($term, $options, $highlight);
                } elseif ($char === 'k') {
                    $highlight = self::nextIndex($highlight, $count, -1);
                    self::redraw($term, $options, $highlight);
                } else {
                    $digit = self::fromDigit($char, $count);
                    if ($digit !== null) {
                        $term->write("\n");
                        return $options[$digit]['key'];
                    }
                }
            }
        } finally {
            $term->write("\e[?25h");
            $term->restoreMode($token);
        }
    }

    /**
     * @param list<array{key: string, label: string}> $options
     */
    private static function redraw(object $term, array $options, int $highlight): void
    {
        $count = count($options);
        $term->write(self::up($count));
        foreach (explode("\n", self::render($options, $highlight)) as $line) {
            $term->write("\e[K" . $line . "\n");
        }
    }

    /**
     * Cooked-mode fallback when raw mode is unavailable: numbered list plus
     * a one-line read. Still no framework prompts.
     *
     * @param list<array{key: string, label: string}> $options
     */
    public static function choosePlain(string $title, array $options): ?string
    {
        if ($options === []) {
            return null;
        }
        fwrite(STDOUT, $title . "\n");
        foreach ($options as $index => $option) {
            fwrite(STDOUT, '  ' . ($index + 1) . '. ' . $option['label'] . "\n");
        }
        fwrite(STDOUT, 'Enter choice [1-' . count($options) . ', q to quit]: ');
        $line = fgets(STDIN);
        if (!is_string($line)) {
            return null;
        }
        $answer = strtolower(trim($line));
        if ($answer === '' || $answer === 'q') {
            return null;
        }
        $digit = self::fromDigit($answer, count($options));
        if ($digit !== null) {
            return $options[$digit]['key'];
        }
        foreach ($options as $option) {
            if (strtolower($option['key']) === $answer) {
                return $option['key'];
            }
        }

        return null;
    }
}
