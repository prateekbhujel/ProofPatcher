<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/**
 * Terminal width detection shared by kernel console commands. Uses the
 * php-terminal extension (prateekbhujel/php-terminal) when it is installed
 * and a TTY answers; otherwise falls back to a fixed width. Never throws.
 */
final class TerminalWidth
{
    public static function columns(int $default = 100): int
    {
        try {
            if (extension_loaded('terminal') && class_exists(\Io\Terminal\Terminal::class)) {
                $size = \Io\Terminal\Terminal::create()->getSize();
                if ($size !== false && isset($size->cols) && $size->cols >= 4) {
                    return min(200, (int) $size->cols);
                }
            }
        } catch (\Throwable) {
        }

        return $default;
    }
}
