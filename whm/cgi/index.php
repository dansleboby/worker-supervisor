<?php

declare(strict_types=1);

use WorkerSupervisor\AdminService;
use WorkerSupervisor\I18n;
use WorkerSupervisor\Paths;
use WorkerSupervisor\ServiceError;

require __DIR__ . '/_bootstrap.php';

// Every worker on the server (spec 6.3), plus a worker's logs (?view=logs) or config (?view=config).

/** Filter group of each status; whm.js filters rows on it. */
const WSV_GROUPS = [
    'failed' => 'failed',
    'stopped' => 'stopped', 'stopping' => 'stopped', 'admin_stopped' => 'stopped',
    'suspended' => 'suspended', 'feature_off' => 'suspended',
    'running' => 'running', 'starting' => 'running',
];
const WSV_PILLS = [
    'running' => 'ok', 'starting' => 'busy', 'stopping' => 'busy', 'stopped' => 'off', 'failed' => 'bad',
    'suspended' => 'warn', 'admin_stopped' => 'warn', 'feature_off' => 'warn',
];
/** Actions offered per status. A suspended or feature-off account gets none: the core refuses them. */
const WSV_CONTROLS = [
    'running' => ['restart' => 'Restart', 'stop' => 'Stop'],
    'starting' => ['restart' => 'Restart', 'stop' => 'Stop'],
    'stopped' => ['start' => 'Start'],
    'failed' => ['start' => 'Start'],
    'admin_stopped' => ['release' => 'Release'],
];
const WSV_LOG_LINES = [200, 1000, 2000];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = wsv_param($_POST, 'user');
    $name = wsv_param($_POST, 'name');
    $query = ['user' => $user, 'name' => $name];
    try {
        switch (wsv_param($_POST, 'action')) {
            case 'stop':
                $admin->adminStop($user, $name);
                $query['notice'] = 'stopped';
                break;
            case 'release':
                $query['notice'] = $admin->release($user, $name) ? 'released_started' : 'released';
                break;
            case 'start':
                $admin->start($user, $name);
                $query['notice'] = 'started';
                break;
            case 'restart':
                $admin->restart($user, $name);
                $query['notice'] = 'restarted';
                break;
            default:
                $query['error'] = 'bad_request';
        }
    } catch (ServiceError $e) {
        $query['error'] = $e->errorCode;
    } catch (Throwable $e) {
        wsv_internal($e);
        $query['error'] = 'internal';
    }
    wsv_redirect('index.php', $query);
}

function wsv_who(string $user, string $name): string
{
    return $user . ' / ' . $name;
}

function wsv_flash(): void
{
    $user = wsv_param($_GET, 'user');
    $who = wsv_who($user, wsv_param($_GET, 'name'));
    $notice = match (wsv_param($_GET, 'notice')) {
        'stopped' => "$who is stopping. Its owner cannot start it again until you release it.",
        'released_started' => "$who is released and starting.",
        'released' => "$who is released. It stays stopped: its owner had stopped it, or the account cannot run workers.",
        'started' => "$who is starting.",
        'restarted' => "$who is restarting.",
        default => null,
    };
    if ($notice !== null) {
        wsv_alert('success', $notice);
    }

    $error = wsv_param($_GET, 'error');
    if ($error !== '') {
        wsv_alert('danger', match ($error) {
            'admin_stopped' => "$who was stopped by an administrator. Use Release to let it run again.",
            'suspended' => "The account $user is suspended, so $who cannot run.",
            'feature_off' => "Worker Supervisor is turned off for $user, so $who cannot run.",
            'worker_unknown' => "$who no longer exists.",
            'account_unknown' => "There is no cPanel account named $user.",
            'account_mismatch' => "The workers stored for $user belong to an earlier account of that name; see journalctl -t worker_supervisor.",
            'bad_request' => 'The request was not valid. Reload the page and try again.',
            default => 'The action failed. Details are in /usr/local/cpanel/logs/error_log.',
        });
    }
}

function wsv_duration(int $seconds, I18n $i18n): string
{
    $s = max(0, $seconds);
    [$d, $h, $m] = [intdiv($s, 86400), intdiv($s % 86400, 3600), intdiv($s % 3600, 60)];

    return match (true) {
        $d > 0 => $i18n->t('duration.days', ['d' => $d, 'h' => $h]),
        $h > 0 => $i18n->t('duration.hours', ['h' => $h, 'm' => $m]),
        $m > 0 => $i18n->t('duration.minutes', ['m' => $m]),
        default => $i18n->t('duration.seconds', ['s' => $s]),
    };
}

function wsv_worker_url(string $view, string $user, string $name, array $extra = []): string
{
    return 'index.php?' . http_build_query(['view' => $view, 'user' => $user, 'name' => $name] + $extra);
}

function wsv_render_overview(array $o, I18n $i18n): void
{
    $health = $o['health'];
    $counts = [
        $health['workers'] . ($health['workers'] === 1 ? ' worker' : ' workers'),
        $health['running_processes'] . ' of ' . $health['processes'] . ' processes running',
    ];
    echo '<p class="wsv-health wsv-health--', $health['running'] ? 'ok' : 'down', '">';
    if ($health['running']) {
        echo '<strong>', wsv_h('supervisord ' . $health['version'] . ' running'), '</strong>';
    } else {
        echo '<strong>supervisord not running</strong>: workers cannot start or stop. ',
            'Start it with <code>systemctl start supervisord</code>.';
    }
    foreach ($counts as $text) {
        echo ' &middot; ', wsv_h($text);
    }
    if ($health['failed'] > 0) {
        echo ' &middot; <span class="wsv-health-failed">', wsv_h($health['failed'] . ' failed'), '</span>';
    }
    echo "</p>\n";

    if ($o['unreadable'] !== []) {
        wsv_alert('warning', 'The worker data of these accounts could not be read, so their workers are not listed: '
            . implode(', ', $o['unreadable']) . '. See /var/cpanel/worker_supervisor/accounts/.');
    }

    echo '<p><a class="btn btn-default btn-sm" href="import.php">Import existing .ini files</a></p>', "\n";

    $rows = $o['rows'];
    if ($rows === []) {
        echo '<p class="wsv-muted">No account has created a worker yet.</p>', "\n";
        return;
    }

    $groups = array_count_values(array_map(static fn (array $r) => WSV_GROUPS[$r['status']] ?? 'stopped', $rows));
    $filters = ['all' => 'All', 'failed' => 'Failed', 'stopped' => 'Stopped', 'suspended' => 'Suspended'];
    echo '<div class="wsv-filters" data-wsv="filters" hidden><div role="group" aria-label="Show workers">';
    foreach ($filters as $group => $label) {
        $count = $group === 'all' ? count($rows) : ($groups[$group] ?? 0);
        echo '<button type="button" class="btn btn-default btn-sm" data-group="', $group, '" aria-pressed="',
            $group === 'all' ? 'true' : 'false', '">', wsv_h("$label ($count)"), '</button>';
    }
    echo '</div><label class="wsv-search">Account <input type="search" class="form-control input-sm" data-wsv="search" ',
        'placeholder="Search account" autocomplete="off"></label></div>', "\n";

    $now = time();
    echo '<div class="table-responsive"><table class="table wsv-table" data-wsv="workers"><thead><tr>',
        '<th scope="col">Account</th><th scope="col">Worker</th><th scope="col">Status</th>',
        '<th scope="col">Processes</th><th scope="col">Up for</th><th scope="col">Actions</th></tr></thead><tbody>', "\n";
    foreach ($rows as $r) {
        $status = $r['status'];
        echo '<tr data-user="', wsv_h($r['user']), '" data-group="', WSV_GROUPS[$status] ?? 'stopped', '">';

        echo '<td><a href="settings.php?user=', rawurlencode($r['user']), '#override" title="',
            wsv_h('Limits of ' . $r['user']), '">', wsv_h($r['user']), '</a>';
        if ($r['over_limit']) {
            echo ' <span class="wsv-pill wsv-pill--bad" title="More workers or processes than its limits allow">Over limit</span>';
        }
        echo '</td>';

        echo '<td><strong>', wsv_h($r['name']), '</strong><div class="wsv-folder">', wsv_h($r['folder']), '</div></td>';

        echo '<td><span class="wsv-pill wsv-pill--', WSV_PILLS[$status] ?? 'off', '">', wsv_h($i18n->t('status.' . $status)), '</span>';
        if ($r['reason'] !== null) {
            echo '<div class="wsv-why">', wsv_h($i18n->t('reason.' . $r['reason']['code'], $r['reason']['params'])), '</div>';
        }
        if ($status === 'admin_stopped') {
            echo '<div class="wsv-hint">Its owner cannot start it until you release it.</div>';
        }
        echo '</td>';

        echo '<td>', wsv_h($r['running'] . ' / ' . $r['processes']), '</td>';
        echo '<td class="wsv-nowrap">', $r['since'] === null ? '-' : wsv_h(wsv_duration($now - $r['since'], $i18n)), '</td>';

        echo '<td><form method="post" action="index.php" class="wsv-actions" aria-label="',
            wsv_h('Actions for ' . wsv_who($r['user'], $r['name'])), '">',
            '<input type="hidden" name="user" value="', wsv_h($r['user']), '">',
            '<input type="hidden" name="name" value="', wsv_h($r['name']), '">';
        foreach (WSV_CONTROLS[$status] ?? [] as $action => $label) {
            echo '<button type="submit" class="btn btn-default btn-sm" name="action" value="', $action, '">', $label, '</button>';
        }
        echo '<a class="btn btn-default btn-sm" href="', wsv_h(wsv_worker_url('logs', $r['user'], $r['name'])), '">Logs</a>',
            '<a class="btn btn-default btn-sm" href="', wsv_h(wsv_worker_url('config', $r['user'], $r['name'])), '">Config</a>',
            '</form></td>';
        echo "</tr>\n";
    }
    echo '<tr data-wsv="no-match" hidden><td colspan="6" class="wsv-muted">No worker matches.</td></tr>';
    echo "</tbody></table></div>\n";
}

function wsv_render_logs(AdminService $admin, string $user, string $name): void
{
    $digits = wsv_param($_GET, 'process');
    $process = ctype_digit($digits) && strlen($digits) <= 3 ? (int) $digits : 0;
    $stream = wsv_param($_GET, 'stream') === 'err' ? 'err' : 'out';
    $lines = (int) wsv_param($_GET, 'lines');
    $lines = in_array($lines, WSV_LOG_LINES, true) ? $lines : WSV_LOG_LINES[0];

    try {
        $log = $admin->logs($user, $name, $process, $stream, $lines);
    } catch (InvalidArgumentException) {
        // A process or stream the worker no longer has, e.g. after its processes were lowered.
        [$process, $stream] = [0, 'out'];
        $log = $admin->logs($user, $name, $process, $stream, $lines);
    }

    echo '<p><a href="index.php">Back to all workers</a></p>';
    echo '<h2 class="wsv-subtitle">', wsv_h('Logs: ' . wsv_who($user, $name)), '</h2>';
    echo '<form method="get" action="index.php" class="wsv-bar">',
        '<input type="hidden" name="view" value="logs">',
        '<input type="hidden" name="user" value="', wsv_h($user), '">',
        '<input type="hidden" name="name" value="', wsv_h($name), '">';

    $processes = array_values(array_unique(array_column($log['files'], 'process')));
    echo '<label>Process <select name="process" class="form-control input-sm">';
    foreach ($processes as $p) {
        echo '<option value="', (int) $p, '"', (int) $p === $process ? ' selected' : '', '>', wsv_h($p), '</option>';
    }
    echo '</select></label>';
    if (in_array('err', array_column($log['files'], 'stream'), true)) {
        echo '<label>Stream <select name="stream" class="form-control input-sm">',
            '<option value="out"', $stream === 'out' ? ' selected' : '', '>Output</option>',
            '<option value="err"', $stream === 'err' ? ' selected' : '', '>Errors</option></select></label>';
    }
    echo '<label>Lines <select name="lines" class="form-control input-sm">';
    foreach (WSV_LOG_LINES as $n) {
        echo '<option value="', $n, '"', $n === $lines ? ' selected' : '', '>', $n, '</option>';
    }
    echo '</select></label><button type="submit" class="btn btn-default btn-sm">Show</button></form>', "\n";

    if ($log['text'] === '') {
        echo '<p class="wsv-muted">Nothing logged yet.</p>';
    } else {
        echo '<pre class="wsv-pre wsv-log" tabindex="0">', wsv_h($log['text']), '</pre>';
    }
}

function wsv_render_config(AdminService $admin, Paths $paths, string $user, string $name): void
{
    $config = $admin->config($user, $name);

    echo '<p><a href="index.php">Back to all workers</a></p>';
    echo '<h2 class="wsv-subtitle">', wsv_h('Config: ' . wsv_who($user, $name)), '</h2>';
    echo '<h3>Generated supervisord program</h3><p class="wsv-muted">', wsv_h($paths->iniFile($user, $name)), '</p>',
        '<pre class="wsv-pre" tabindex="0">', wsv_h($config['ini']), '</pre>';
    echo '<h3>Worker as its owner defined it</h3>',
        '<p class="wsv-muted">Folder and command reach the worker through a root-only launch file, never through the program file.</p>',
        '<pre class="wsv-pre" tabindex="0">', wsv_h($config['expert']), '</pre>';
}

wsv_page_start('index');
wsv_flash();
$view = wsv_param($_GET, 'view');
try {
    if ($view === 'logs' || $view === 'config') {
        $user = wsv_param($_GET, 'user');
        $name = wsv_param($_GET, 'name');
        try {
            if ($view === 'logs') {
                wsv_render_logs($admin, $user, $name);
            } else {
                wsv_render_config($admin, $paths, $user, $name);
            }
        } catch (ServiceError $e) {
            echo '<p><a href="index.php">Back to all workers</a></p>';
            wsv_alert('danger', $e->errorCode === 'account_unknown'
                ? "There is no cPanel account named $user."
                : wsv_who($user, $name) . ' does not exist.');
        }
    } else {
        wsv_render_overview($admin->overview(), $i18n);
    }
} catch (Throwable $e) {
    wsv_alert('danger', wsv_internal($e));
}
wsv_page_end();
