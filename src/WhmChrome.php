<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/**
 * Works around a cPanel bug on PHP plugin pages: WHM.php serves a header pre-rendered into
 * /var/cpanel/caches/_generated_header_files/, with root's analytics consent frozen as it was at
 * cache time. Once root has chosen, native WHM pages read the choice live, but plugin pages keep
 * reopening the "Consent and Privacy" slideout until scripts/rebuild_whm_chrome runs. The live
 * choice, from root's WHM NVData, replaces the frozen one; a header without the expected script
 * (another cPanel version) is left as it is.
 */
final class WhmChrome
{
    public const ROOT_NVDATA = '/var/cpanel/whm/nvdata/root.yaml';

    /**
     * Root's WHM NVData (Whostmgr::NVData, YAML) as saved by the slideout through json-api nvset.
     *
     * @return array{analytics: ?string, dismissed: bool} analytics: on, off, or null before any choice
     */
    public static function consent(string $nvdataFile = self::ROOT_NVDATA): array
    {
        $text = is_file($nvdataFile) ? (string) @file_get_contents($nvdataFile, false, null, 0, 1048576) : '';
        $analytics = preg_match('/^analytics:\s*[\'"]?(on|off)[\'"]?\s*$/m', $text, $m) === 1 ? $m[1] : null;

        return [
            'analytics' => $analytics,
            'dismissed' => preg_match('/^slideoutDismissed:\s*[\'"]?1[\'"]?\s*$/m', $text) === 1,
        ];
    }

    /**
     * The slideout opens by itself only while currentConsentSetting is null and slideoutDismissed
     * false; the template assigns slideoutDismissed twice, and the second (bare) assignment wins.
     */
    public static function withLiveConsent(string $header, ?string $analytics, bool $dismissed): string
    {
        if ($analytics !== null) {
            $header = preg_replace(
                '/\bvar currentConsentSetting = null;/',
                'var currentConsentSetting = ' . ($analytics === 'on' ? 'true' : 'false') . ';',
                $header,
                1,
            ) ?? $header;
        }
        if ($dismissed) {
            $header = preg_replace('/^(\s*)slideoutDismissed = false;$/m', '$1slideoutDismissed = true;', $header, 1) ?? $header;
        }

        return $header;
    }
}
