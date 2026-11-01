<?php

declare(strict_types=1);

namespace WorkerSupervisor;

use WorkerSupervisor\Supervisor\ShellRunner;
use WorkerSupervisor\Supervisor\Supervisorctl;

/** Wires the production object graph. Tests build their own from fakes. */
final class Container
{
    /** @return array{service: Service, admin: AdminService, lifecycle: Lifecycle, importer: Importer} */
    public static function build(Paths $p): array
    {
        $runner = new ShellRunner();
        $store = new AccountStore($p);
        // One instance for every service: an admin change to the limits applies to the same request.
        $settings = Settings::load($p);
        $ini = new IniRenderer($p);
        $sv = new Supervisorctl($runner);
        $cpusers = static fn (string $user): ?CpUser => CpUser::load($p, $user);
        $logs = new Logs($p);

        $service = new Service(
            $p,
            $store,
            $settings,
            $ini,
            $sv,
            $logs,
            new WorkerValidator(new LveProbe($runner)),
            $cpusers,
        );

        return [
            'service' => $service,
            'admin' => new AdminService($service, $store, $settings, $sv, $cpusers, $ini),
            'importer' => new Importer($p, $service, $sv, $ini),
            'lifecycle' => new Lifecycle($store, $settings, $ini, $sv, $logs, $cpusers, new WhmapiFeatureChecker($runner)),
        ];
    }
}
