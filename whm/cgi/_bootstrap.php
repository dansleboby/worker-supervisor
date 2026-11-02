<?php

declare(strict_types=1);

// Shared head of the WHM pages: root only, English only (no documented way to read root's locale).
// Exposes $admin (AdminService), $importer (Importer), $paths and $i18n (the English catalog, for status and template labels).
//
// Spike on cPanel 11.138 (2026-10-31), with a hello page under whostmgr/docroot/cgi/:
// - cpsrvd runs every *.php there through its own /usr/local/cpanel/3rdparty/bin/php-cgi (SAPI
//   cgi-fcgi, PHP 8.4) as root, whatever the file mode and with or without a shebang (php-cgi skips
//   a #! line). Keep this code 8.1-compatible all the same.
// - Nothing runs unless an AppConfig `url` covers the path: 403 even for root otherwise.
// - `entryurl` is relative to whostmgr/docroot/cgi/: worker_supervisor/index.php becomes the
//   menu link /cpsessNNN/cgi/worker_supervisor/index.php.
// - REMOTE_USER is set in both getenv() and $_ENV; a request without the cpsess token in its path
//   gets a 401 from cpsrvd before PHP runs, which is the CSRF guard for the POST actions.
// - Other files there are served as static files with a 60-day cache: assets carry ?v=<mtime>.

$wsvRemoteUser = getenv('REMOTE_USER');
if (!is_string($wsvRemoteUser)) {
    $wsvRemoteUser = $_ENV['REMOTE_USER'] ?? null;
}
// acls=all also admits a reseller holding every privilege; limits and other accounts are root's alone.
if ($wsvRemoteUser !== 'root') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Worker Supervisor is available to root only.\n";
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once '/usr/local/cpanel/php/WHM.php';
require_once '/usr/local/cpanel/3rdparty/worker_supervisor/src/autoload.php';

$paths = new \WorkerSupervisor\Paths();
$wsvServices = \WorkerSupervisor\Container::build($paths);
$admin = $wsvServices['admin'];
$importer = $wsvServices['importer'];
$i18n = new \WorkerSupervisor\I18n($paths->appDir() . '/lang', 'en');
define('WSV_VERSION', \WorkerSupervisor\Version::read($paths->appDir() . '/VERSION'));

function wsv_h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Relative URL of a file in assets/, versioned because cpsrvd lets browsers cache it for 60 days. */
function wsv_asset(string $file): string
{
    return 'assets/' . $file . '?v=' . (int) @filemtime(__DIR__ . '/assets/' . $file);
}

/** @param string $tab index or settings */
function wsv_page_start(string $tab): void
{
    // WHM::header pastes the title into the page unescaped. Its cached header can carry a stale
    // analytics consent that reopens cPanel's slideout on every load (WhmChrome).
    $consent = \WorkerSupervisor\WhmChrome::consent();
    echo \WorkerSupervisor\WhmChrome::withLiveConsent(
        (string) WHM::getHeaderString(wsv_h('Worker Supervisor'), 0, 0),
        $consent['analytics'],
        $consent['dismissed'],
    );
    $tabs = ['index' => 'Workers', 'settings' => 'Limits'];
    echo '<link rel="stylesheet" href="', wsv_h(wsv_asset('whm.css')), '">', "\n";
    echo '<div class="wsv"><h1 class="wsv-title">Worker Supervisor</h1><nav class="wsv-nav" aria-label="Worker Supervisor">';
    foreach ($tabs as $page => $label) {
        echo '<a href="', $page, '.php"', $page === $tab ? ' aria-current="page"' : '', '>', wsv_h($label), '</a>';
    }
    echo "</nav>\n";
}

function wsv_page_end(): void
{
    echo '<p class="wsv-version wsv-muted">Worker Supervisor ', wsv_h(WSV_VERSION), '</p>';
    echo '</div><script src="', wsv_h(wsv_asset('whm.js')), '"></script>', "\n";
    WHM::footer();
}

/** @param string $kind success, info, warning or danger */
function wsv_alert(string $kind, string $text): void
{
    echo '<div class="alert alert-', $kind, ' wsv-alert" role="', $kind === 'danger' ? 'alert' : 'status', '">',
        wsv_h($text), '</div>', "\n";
}

/** A request value, '' when missing or not a string (PHP turns name[]=x into an array). */
function wsv_param(array $source, string $key): string
{
    $value = $source[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * Post/redirect/get: a reload after an action shows the result instead of resending the form.
 *
 * @param array<string, scalar> $query
 */
function wsv_redirect(string $page, array $query = []): never
{
    http_response_code(303);
    header('Location: ' . $page . ($query === [] ? '' : '?' . http_build_query($query)));
    exit;
}

/** Logs an unexpected failure for cpsrvd's error_log and returns the text root sees. */
function wsv_internal(Throwable $e): string
{
    error_log('worker_supervisor: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());

    return 'Unexpected error: ' . $e->getMessage() . ' (details in /usr/local/cpanel/logs/error_log)';
}
