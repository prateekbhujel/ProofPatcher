<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/** Keyboard action launcher. It never runs a command or fetches remote data. */
final class TerminalDashboard
{
    /** @param list<array{key: string, label: string, detail?: string}> $options */
    public static function matches(array $options, string $query): array
    {
        $query = mb_strtolower(trim($query), 'UTF-8');
        if ($query === '') return array_values($options);

        return array_values(array_filter($options, static function (array $option) use ($query): bool {
            $text = self::safeText($option['label'].' '.($option['detail'] ?? '').' '.$option['key']);
            return str_contains(mb_strtolower($text, 'UTF-8'), $query);
        }));
    }

    /** @param list<array{key: string, label: string, detail?: string}> $options
     *  @param list<string> $status */
    public static function paint(array $options, int $selected, string $query, bool $searching, array $status, int $width, int $height): string
    {
        $width = max(4, $width);
        $height = max(4, $height);
        $lines = [];
        $lines[] = "\e[1m".self::fit('proofpatcher · hari kernel', $width)."\e[0m";
        if ($height >= 9) $lines[] = "\e[2m".self::fit('repository maintenance console', $width)."\e[0m";
        if ($height >= 10) {
            foreach (array_slice($status, 0, min(3, $height - 8)) as $line) {
                $lines[] = self::fit(self::safeText($line), $width);
            }
        }
        $lines[] = self::fit($searching ? 'Search: '.self::safeText($query).'_' : ($query !== '' ? 'Filter: '.self::safeText($query) : 'Actions'), $width);

        // Reserve one final line for controls; the options viewport scrolls
        // around the selected row when the terminal is short.
        $capacity = max(0, $height - count($lines) - 1);
        $selected = $options === [] ? 0 : max(0, min(count($options) - 1, $selected));
        $start = max(0, min($selected - $capacity + 1, max(0, count($options) - $capacity)));
        foreach (array_slice($options, $start, $capacity, true) as $index => $option) {
            $prefix = $index === $selected ? '❯ ' : '  ';
            $label = self::safeText($option['label']);
            $detail = self::safeText((string) ($option['detail'] ?? ''));
            $shortcut = $index < 9 ? ($index + 1).'. ' : '   ';
            $text = $prefix.$shortcut.$label;
            if ($detail !== '' && $width >= 72) {
                $column = min(34, intdiv($width, 2));
                $text = self::fit($text, $column - 2);
                $text .= str_repeat(' ', max(2, $column - mb_strwidth($text, 'UTF-8'))).$detail;
            }
            $row = self::fit($text, $width);
            $lines[] = $index === $selected ? "\e[1;36m".$row."\e[0m" : $row;
        }
        if ($options === [] && $capacity > 0) $lines[] = self::fit('  No matching actions', $width);
        $hint = $searching ? 'Type to filter · Enter select · Esc cancel' : '↑↓ move · Enter open · 1–9 · / search · q quit';
        $lines[] = "\e[2m".self::fit($hint, $width)."\e[0m";

        return "\e[H\e[2J".implode("\r\n", array_slice($lines, 0, $height));
    }

    /** Remove terminal control sequences from user/account supplied text. */
    private static function safeText(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $text);
    }

    private static function fit(string $text, int $width): string
    {
        $text = self::safeText($text);
        // Reserve the final cell to avoid triggering a terminal's line wrap.
        $max = max(1, $width - 1);
        return mb_strwidth($text, 'UTF-8') <= $max ? $text : mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }

    /** @return array{int, int} */
    private static function size(object $term): array
    {
        try {
            $size = $term->getSize();
            if (is_object($size) && is_int($size->cols ?? null) && is_int($size->rows ?? null)
                && $size->cols >= 4 && $size->rows >= 4) {
                return [min(240, $size->cols), min(120, $size->rows)];
            }
        } catch (\Throwable) {
        }
        return [80, 24];
    }

    /**
     * @param list<array{key: string, label: string, detail?: string}> $options
     * @param list<string> $status Local, non-secret state shown in the header.
     */
    public static function choose(object $term, array $options, array $status = [], ?string $initialKey = null): ?string
    {
        if ($options === []) return null;
        $raw = $term->enableRawMode();
        if ($raw === false) throw new \RuntimeException('Could not enable terminal input. Try an interactive terminal.');
        $searching = false;
        $query = '';
        $selected = 0;
        foreach ($options as $index => $option) if ($option['key'] === $initialKey) $selected = $index;

        try {
            $term->write("\e[?1049h\e[?25l");
            $emptyReads = 0;
            while (true) {
                $filtered = self::matches($options, $query);
                $selected = $filtered === [] ? 0 : max(0, min(count($filtered) - 1, $selected));
                [$width, $height] = self::size($term);
                $term->write(self::paint($filtered, $selected, $query, $searching, $status, $width, $height));
                $key = $term->readKey();
                if ($key === false || $key === null) {
                    if (++$emptyReads >= 100) throw new \RuntimeException('Terminal input is unavailable.');
                    usleep(10000);
                    continue;
                }
                $emptyReads = 0;
                if ($key instanceof \Io\Terminal\Key) {
                    if ($key === \Io\Terminal\Key::Resize) continue;
                    if ($key === \Io\Terminal\Key::Escape) {
                        if ($searching || $query !== '') { $searching = false; $query = ''; $selected = 0; continue; }
                        return null;
                    }
                    if ($key === \Io\Terminal\Key::Backspace && $searching) {
                        $query = mb_substr($query, 0, max(0, mb_strlen($query, 'UTF-8') - 1), 'UTF-8');
                        $selected = 0;
                        continue;
                    }
                    if ($key === \Io\Terminal\Key::Up) $selected = TerminalMenu::nextIndex($selected, count($filtered), -1);
                    elseif ($key === \Io\Terminal\Key::Down) $selected = TerminalMenu::nextIndex($selected, count($filtered), 1);
                    elseif ($key === \Io\Terminal\Key::Home) $selected = 0;
                    elseif ($key === \Io\Terminal\Key::End) $selected = max(0, count($filtered) - 1);
                    elseif ($key === \Io\Terminal\Key::Enter && $filtered !== []) return $filtered[$selected]['key'];
                    continue;
                }
                $key = (string) $key;
                if ($key === "\x03") return null;
                if ($searching) {
                    if ($key === "\r" || $key === "\n") { if ($filtered !== []) return $filtered[$selected]['key']; continue; }
                    if ($key === "\x7f" || $key === "\x08") { $query = mb_substr($query, 0, max(0, mb_strlen($query) - 1)); $selected = 0; continue; }
                    if (preg_match('/\A[\pL\pN _.-]\z/u', $key) && mb_strlen($query) < 80) { $query .= $key; $selected = 0; }
                    continue;
                }
                if ($key === 'q' || $key === 'Q') return null;
                if ($key === '/') { $searching = true; $query = ''; $selected = 0; continue; }
                if (($key === "\r" || $key === "\n") && $filtered !== []) return $filtered[$selected]['key'];
                $digit = TerminalMenu::fromDigit($key, count($filtered));
                if ($digit !== null) return $filtered[$digit]['key'];
                if ($key === 'j') $selected = TerminalMenu::nextIndex($selected, count($filtered), 1);
                elseif ($key === 'k') $selected = TerminalMenu::nextIndex($selected, count($filtered), -1);
            }
        } finally {
            try {
                $term->write("\e[?25h\e[?1049l");
            } finally {
                $term->restoreMode($raw);
            }
        }
    }
}
