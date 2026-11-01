<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use RuntimeException;

final class Fs
{
    /** Symlinks inside are unlinked, never followed; a missing path is not an error. */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException("Cannot remove $path.");
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        if (!@rmdir($path)) {
            throw new RuntimeException("Cannot remove $path.");
        }
    }
}
