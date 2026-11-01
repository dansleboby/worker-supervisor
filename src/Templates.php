<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class Templates
{
    /** @return Template[] */
    public static function all(): array
    {
        return [
            new Template(
                id: 'laravel-queue',
                command: 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600',
                requiredPaths: ['artisan'],
                fixedProcesses: null,
                laravelRestart: true,
                memoryFlagPattern: '/--memory=(\d+)/',
            ),
            new Template(
                id: 'laravel-horizon',
                command: 'php artisan horizon',
                requiredPaths: ['artisan', 'vendor/laravel/horizon'],
                fixedProcesses: 1,
                laravelRestart: true,
                memoryFlagPattern: null,
            ),
            new Template(
                id: 'symfony-messenger',
                command: 'php bin/console messenger:consume async --time-limit=3600',
                requiredPaths: ['bin/console'],
                fixedProcesses: null,
                laravelRestart: false,
                memoryFlagPattern: '/--memory-limit=(\d+)M/i',
            ),
            new Template(
                id: 'custom',
                command: '',
                requiredPaths: [],
                fixedProcesses: null,
                laravelRestart: false,
                memoryFlagPattern: null,
            ),
        ];
    }

    public static function get(string $id): ?Template
    {
        foreach (self::all() as $template) {
            if ($template->id === $id) {
                return $template;
            }
        }

        return null;
    }
}
