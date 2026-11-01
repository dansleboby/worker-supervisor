<?php

declare(strict_types=1);

// The installed copy has no Composer; this maps WorkerSupervisor\ to this directory.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WorkerSupervisor\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
