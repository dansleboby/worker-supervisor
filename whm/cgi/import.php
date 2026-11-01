<?php

declare(strict_types=1);

use WorkerSupervisor\Importer;
use WorkerSupervisor\Issue;
use WorkerSupervisor\ServiceError;

require __DIR__ . '/_bootstrap.php';

// Import of hand-written /etc/supervisord.d/*.ini programs as managed workers (spec 6.5):
// pick a program, pick the owning account, review what changes, apply.

const WSV_CHANGE_TEXT = [
    'wrapper_replaced' => 'The su wrapper is replaced by the plugin launcher, which starts the worker inside the account\'s CageFS and LVE.',
    'directory_from_cd' => 'The folder is taken from the "cd" in the command.',
    'logs_moved' => 'Log files move to /var/log/worker_supervisor/<account>/ and the old log paths are no longer written.',
    'user_key_dropped' => 'user= is dropped: the worker runs as the account.',
];
const WSV_WORKER_FIELDS = ['name', 'template', 'folder', 'command', 'processes', 'autostart', 'autorestart', 'startsecs', 'startretries', 'stopwaitsecs', 'redirect_stderr'];

const WSV_REASON_TEXT = [
    'program_name_unsupported' => 'This program name has characters the plugin cannot manage (use letters, digits, ".", "_" and "-"). Rename it in the file first.',
    'program_name_collision' => 'The new worker would get the same supervisor name as the program being replaced. Choose another worker name.',
];

/** @return ?string a warning shown above the list of changes, for the account warnings */
function wsv_account_warning(string $change): ?string
{
    $parts = explode(':', $change);
    return match ($parts[0]) {
        'user_mismatch' => sprintf('This program ran as %s, but you are importing it for %s. The worker will run as %2$s: check this is the right account.', $parts[1] ?? '?', $parts[2] ?? '?'),
        'ran_as_root' => 'This program ran as root, with no su wrapper. The worker will run as the account you chose, with that account\'s permissions and limits.',
        default => null,
    };
}

function wsv_change_text(string $change): string
{
    if (str_starts_with($change, 'key_dropped:')) {
        return substr($change, strlen('key_dropped:')) . ' is not supported and is dropped.';
    }

    return WSV_CHANGE_TEXT[$change] ?? $change;
}

/** @param Issue[] $issues */
function wsv_import_issues(array $issues, \WorkerSupervisor\I18n $i18n): void
{
    foreach ($issues as $issue) {
        wsv_alert($issue->level === 'error' ? 'danger' : 'warning', $issue->field . ': ' . $i18n->t('issue.' . $issue->code, $issue->params));
    }
}

/** @return array<string, string> the posted worker fields, as typed */
function wsv_posted_worker(): array
{
    $out = [];
    foreach (WSV_WORKER_FIELDS as $key) {
        $out[$key] = wsv_param($_POST, $key);
    }

    return $out;
}

$input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$file = wsv_param($input, 'file');
$program = wsv_param($input, 'program');
$user = wsv_param($input, 'user');
/** @var Issue[] */
$applyIssues = [];
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (wsv_param($_POST, 'action') !== 'apply' || preg_match('/^[a-z][a-z0-9]{0,15}$/D', $user) !== 1) {
            throw new ServiceError('bad_request');
        }
        $importer->apply($file, $program, $user, wsv_posted_worker());
        wsv_redirect('import.php', ['done' => $user . ' / ' . wsv_param($_POST, 'name')]);
    } catch (ServiceError $e) {
        $applyIssues = $e->issues;
        $error = match ($e->errorCode) {
            'validation_failed' => 'Nothing was changed: fix the problems below.',
            'account_unknown' => "There is no cPanel account named $user.",
            'account_mismatch' => "The workers stored for $user belong to an earlier account of that name; resolve that first (journalctl -t worker_supervisor).",
            'bad_request' => isset($e->params['reason'], WSV_REASON_TEXT[$e->params['reason']])
                ? 'Nothing was changed. ' . WSV_REASON_TEXT[$e->params['reason']]
                : 'The request was not valid. Reload the page and try again.',
            default => 'The import was refused (' . $e->errorCode . '). Reload the page and try again.',
        };
    } catch (Throwable $e) {
        $error = wsv_internal($e);
    }
}

function wsv_render_list(Importer $importer): void
{
    $candidates = $importer->candidates();
    if ($candidates === []) {
        echo '<p class="wsv-muted">No hand-written program files were found in /etc/supervisord.d/.</p>';

        return;
    }
    echo '<p class="wsv-muted">Programs in /etc/supervisord.d/ that the plugin does not manage. ',
        'You review the converted worker before anything changes.</p>',
        '<div class="table-responsive"><table class="table wsv-table"><thead><tr>',
        '<th scope="col">File</th><th scope="col">Program</th><th scope="col">Account</th><th scope="col">Actions</th></tr></thead><tbody>';
    foreach ($candidates as $c) {
        foreach ($c['programs'] as $prog) {
            if (!$prog['importable']) {
                echo '<tr><td>', wsv_h($c['file']), '</td><td><strong>', wsv_h($prog['name']), '</strong></td><td>',
                    '<span class="wsv-muted">-</span></td><td><span class="wsv-muted">Cannot be imported: ',
                    wsv_h(WSV_REASON_TEXT[$prog['reason']] ?? $prog['reason']), '</span></td></tr>';
                continue;
            }
            $query = http_build_query(['file' => $c['file'], 'program' => $prog['name'], 'user' => $prog['suggested_user']]);
            echo '<tr><td>', wsv_h($c['file']), '</td><td><strong>', wsv_h($prog['name']), '</strong></td><td>',
                $prog['suggested_user'] === '' ? '<span class="wsv-muted">-</span>' : wsv_h($prog['suggested_user']),
                '</td><td><a class="btn btn-default btn-sm" href="import.php?', wsv_h($query), '">Preview</a></td></tr>';
        }
    }
    echo '</tbody></table></div>';
}

function wsv_render_preview(Importer $importer, \WorkerSupervisor\I18n $i18n, string $file, string $program, string $user, array $applyIssues): void
{
    global $paths;
    $accounts = array_values(array_filter(
        array_map('basename', glob(dirname($paths->cpuserFile('x')) . '/*') ?: []),
        static fn (string $u) => preg_match('/^[a-z][a-z0-9]{0,15}$/D', $u) === 1,
    ));
    $title = wsv_h($file . ' / ' . $program);

    echo '<p><a href="import.php">Back to the list</a></p><h2 class="wsv-subtitle">Import ', $title, '</h2>';
    echo '<form method="get" action="import.php" class="wsv-bar"><input type="hidden" name="file" value="', wsv_h($file),
        '"><input type="hidden" name="program" value="', wsv_h($program), '">',
        '<label>Account <input type="text" name="user" class="form-control input-sm" list="wsv-accounts" required autocomplete="off" value="',
        wsv_h($user), '"></label><datalist id="wsv-accounts">';
    foreach ($accounts as $account) {
        echo '<option value="', wsv_h($account), '"></option>';
    }
    echo '</datalist><button type="submit" class="btn btn-default btn-sm">Preview</button></form>';

    if ($user === '') {
        echo '<p class="wsv-muted">Choose the account that will own this worker.</p>';

        return;
    }
    if (preg_match('/^[a-z][a-z0-9]{0,15}$/D', $user) !== 1) {
        wsv_alert('danger', "There is no cPanel account named $user.");

        return;
    }

    try {
        $proposal = $importer->propose($file, $program, $user);
    } catch (ServiceError $e) {
        wsv_alert('danger', $e->errorCode === 'account_unknown' ? "There is no cPanel account named $user." : 'This program no longer exists in that file.');

        return;
    }

    $posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $w = $posted ? wsv_posted_worker() : array_map(
        static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
        array_intersect_key($proposal['worker'], array_flip(WSV_WORKER_FIELDS)),
    );

    wsv_import_issues($posted ? $applyIssues : $proposal['issues'], $i18n);

    foreach ($proposal['changes'] as $change) {
        $warning = wsv_account_warning($change);
        if ($warning !== null) {
            wsv_alert('warning', $warning);
        }
    }

    echo '<h3>What changes</h3><ul>';
    foreach ($proposal['changes'] as $change) {
        if (wsv_account_warning($change) !== null) {
            continue;
        }
        echo '<li>', wsv_h(wsv_change_text($change)), '</li>';
    }
    echo '<li>The original file is copied to ', wsv_h($paths->backupDir()), ' and the program is removed from it; the old process is stopped.</li></ul>';

    echo '<form method="post" action="import.php" class="wsv-form" novalidate>',
        '<input type="hidden" name="action" value="apply">',
        '<input type="hidden" name="file" value="', wsv_h($file), '">',
        '<input type="hidden" name="program" value="', wsv_h($program), '">',
        '<input type="hidden" name="user" value="', wsv_h($user), '">';
    foreach (['template', 'autostart', 'autorestart', 'startsecs', 'startretries', 'stopwaitsecs', 'redirect_stderr'] as $key) {
        echo '<input type="hidden" name="', $key, '" value="', wsv_h($w[$key]), '">';
    }
    $fields = ['name' => 'Worker name', 'folder' => 'Folder', 'command' => 'Command', 'processes' => 'Processes'];
    foreach ($fields as $key => $label) {
        echo '<div class="form-group"><label for="wsv-import-', $key, '">', wsv_h($label), '</label>',
            '<input type="text" class="form-control" id="wsv-import-', $key, '" name="', $key, '" value="', wsv_h($w[$key]), '" autocomplete="off"></div>';
    }
    echo '<p class="wsv-muted">', wsv_h(sprintf(
        'Template: %s. Restart if it stops: %s. Start wait %s s, retries %s, stop wait %s s.',
        $i18n->t('template.' . $w['template']),
        $w['autorestart'],
        $w['startsecs'],
        $w['startretries'],
        $w['stopwaitsecs'],
    )), '</p>',
        '<button type="submit" class="btn btn-primary">Import as ', wsv_h($user), '</button></form>';
}

wsv_page_start('import');
$done = wsv_param($_GET, 'done');
if ($done !== '') {
    wsv_alert('success', "$done is imported and starting. The original file was backed up.");
}
if ($error !== null) {
    wsv_alert('danger', $error);
}
try {
    if ($file !== '' && $program !== '') {
        wsv_render_preview($importer, $i18n, $file, $program, $user, $applyIssues);
    } else {
        wsv_render_list($importer);
    }
} catch (Throwable $e) {
    wsv_alert('danger', wsv_internal($e));
}
wsv_page_end();
