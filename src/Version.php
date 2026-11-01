<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/** The release named by a VERSION file: the repository's, or the installed copy install.sh writes last. */
final class Version
{
    public const UNKNOWN = 'dev';

    /** UNKNOWN when the file is missing or does not hold a single semantic version. */
    public static function read(string $file): string
    {
        $text = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($text)) {
            return self::UNKNOWN;
        }
        $version = trim($text);

        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $version) === 1 ? $version : self::UNKNOWN;
    }
}
