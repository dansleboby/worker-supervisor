<?php

declare(strict_types=1);

namespace WorkerSupervisor;

final class LimitResolver
{
    private const PREFIX = 'worker_supervisor_';

    /** Each field resolves independently: per-account override > package extension > server defaults. */
    public static function resolve(Settings $s, CpUser $u): Limits
    {
        $base = $s->defaults()->toArray();
        // Only root-owned packages are trusted; a reseller can create packages carrying arbitrary extension values.
        $ext = $u->owner === 'root' ? self::fromExtension($u->ext, $base['templates']) : [];
        $over = $s->override($u->user) ?? [];

        return Limits::fromArray(array_replace($base, $ext, $over));
    }

    /** @param string[] $baseTemplates */
    private static function fromExtension(array $ext, array $baseTemplates): array
    {
        $out = [];
        foreach (['max_processes', 'max_workers', 'max_stopwaitsecs'] as $k) {
            $v = $ext[self::PREFIX . $k] ?? null;
            if ($v !== null && ctype_digit($v)) {
                $out[$k] = (int) $v;
            }
        }

        // A template with no key in the package keeps the lower-precedence answer.
        $templates = [];
        $any = false;
        foreach (Templates::all() as $t) {
            $v = $ext[self::PREFIX . 'tpl_' . str_replace('-', '_', $t->id)] ?? null;
            $any = $any || $v !== null;
            if ($v === null ? in_array($t->id, $baseTemplates, true) : $v === '1') {
                $templates[] = $t->id;
            }
        }
        if ($any) {
            $out['templates'] = $templates;
        }

        return $out;
    }
}
