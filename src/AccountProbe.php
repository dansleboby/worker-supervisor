<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/** Facts about a cPanel account that can only be read from the live system. */
interface AccountProbe
{
    /** Account memory limit in MB, or null when unlimited or unknown. */
    public function memoryLimitMb(string $user): ?int;

    /** PHP version the account uses for the folder, or null when it cannot be determined. */
    public function phpVersion(string $user, string $folder): ?string;
}
