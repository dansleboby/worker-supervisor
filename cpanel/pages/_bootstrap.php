<?php

declare(strict_types=1);

// Shared head of every Worker Supervisor page. Pages run as the cPanel user, outside CageFS, under
// cPanel's own PHP (/usr/local/cpanel/3rdparty/php/84/bin/php-cgi on 11.138); keep this 8.1-compatible.
// Exposes $cpanel (LiveAPI) and $i18n. A .live.php URL is served even when the feature is off, hence the guard.

require_once '/usr/local/cpanel/php/cpanel.php';
require_once '/usr/local/cpanel/3rdparty/worker_supervisor/src/I18n.php';
require_once '/usr/local/cpanel/3rdparty/worker_supervisor/src/Version.php';

const WSV_FEATURE = 'worker_supervisor';
define('WSV_VERSION', \WorkerSupervisor\Version::read('/usr/local/cpanel/3rdparty/worker_supervisor/VERSION'));

$cpanel = new CPANEL();
$wsvLocale = $cpanel->uapi('Locale', 'get_attributes')['cpanelresult']['result']['data']['locale'] ?? 'en';
$i18n = new \WorkerSupervisor\I18n(
    '/usr/local/cpanel/3rdparty/worker_supervisor/lang',
    is_string($wsvLocale) ? $wsvLocale : 'en',
);

function wsv_h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Prints Jupiter's chrome, the plugin stylesheet and window.WSV_I18N / WSV_CONTEXT for app.js. */
function wsv_page_start(CPANEL $cpanel, \WorkerSupervisor\I18n $i18n, string $title): void
{
    // Chrome::get_dom inserts the title into the page unescaped.
    print $cpanel->header(wsv_h($title), WSV_FEATURE);

    $context = [
        'locale' => $i18n->locale(),
        'home' => (string) (posix_getpwuid(posix_geteuid())['dir'] ?? ''),
        // Lets the browser compute uptimes from server timestamps whatever its own clock says.
        'now' => time(),
    ];
    // JSON_HEX_* keeps "</script>" and quotes in a message from ending the script element.
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    echo '<link rel="stylesheet" href="', wsv_h(wsv_asset('app.css')), '">', "\n";
    echo '<script>window.WSV_I18N = ', json_encode($i18n->catalog(), $flags),
        '; window.WSV_CONTEXT = ', json_encode($context, $flags), ';</script>', "\n";
    echo '<script src="', wsv_h(wsv_asset('app.js')), '"></script>', "\n";
}

function wsv_page_end(CPANEL $cpanel, \WorkerSupervisor\I18n $i18n): void
{
    echo '<div class="wsv"><p class="wsv-version wsv-muted">', wsv_h($i18n->t('app.title') . ' ' . WSV_VERSION), '</p></div>', "\n";
    print $cpanel->footer();
    $cpanel->end();
}

/** Relative URL of a file in assets/, versioned so an upgrade is never served from the browser cache. */
function wsv_asset(string $file): string
{
    return 'assets/' . $file . '?v=' . (int) @filemtime(__DIR__ . '/assets/' . $file);
}

if (!$cpanel->cpanelfeature(WSV_FEATURE)) {
    wsv_page_start($cpanel, $i18n, $i18n->t('app.title'));
    echo '<div class="alert alert-warning"><span class="glyphicon glyphicon-exclamation-sign"></span>',
        '<div class="alert-message">', wsv_h($i18n->t('page.not_available')), '</div></div>';
    wsv_page_end($cpanel, $i18n);
    exit;
}
