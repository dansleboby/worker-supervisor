<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\WhmChrome;
use PHPUnit\Framework\TestCase;

final class WhmChromeTest extends TestCase
{
    private const HEADER = __DIR__ . '/../fixtures/whm/cached-analytics-header.html';

    private string $nvdata;

    protected function setUp(): void
    {
        $this->nvdata = tempnam(sys_get_temp_dir(), 'wsv-nvdata-');
    }

    protected function tearDown(): void
    {
        @unlink($this->nvdata);
    }

    public function test_reads_root_s_consent_choice_from_its_whm_nvdata(): void
    {
        file_put_contents($this->nvdata, "---\nanalytics: 'on'\nslideoutDismissed: '1'\nother: x\n");
        $this->assertSame(['analytics' => 'on', 'dismissed' => true], WhmChrome::consent($this->nvdata));

        file_put_contents($this->nvdata, "---\nanalytics: off\n");
        $this->assertSame(['analytics' => 'off', 'dismissed' => false], WhmChrome::consent($this->nvdata));

        unlink($this->nvdata);
        $this->assertSame(['analytics' => null, 'dismissed' => false], WhmChrome::consent($this->nvdata), 'no choice yet');
    }

    public function test_a_choice_made_after_the_cache_stops_the_slideout_from_opening(): void
    {
        $cached = (string) file_get_contents(self::HEADER);

        $live = WhmChrome::withLiveConsent($cached, 'on', true);

        $this->assertStringContainsString('var currentConsentSetting = true;', $live);
        $this->assertMatchesRegularExpression('/^\s*slideoutDismissed = true;$/m', $live);
        $this->assertStringContainsString('var slideoutDismissed = false;', $live, 'only the assignment that wins is changed');
        $this->assertStringContainsString('if(currentConsentSetting === null && !slideoutDismissed)', $live);
    }

    public function test_declining_or_dismissing_alone_is_enough(): void
    {
        $cached = (string) file_get_contents(self::HEADER);

        $this->assertStringContainsString('var currentConsentSetting = false;', WhmChrome::withLiveConsent($cached, 'off', false));
        $this->assertMatchesRegularExpression('/^\s*slideoutDismissed = true;$/m', WhmChrome::withLiveConsent($cached, null, true));
    }

    public function test_without_a_choice_or_the_expected_script_the_header_is_unchanged(): void
    {
        $cached = (string) file_get_contents(self::HEADER);

        $this->assertSame($cached, WhmChrome::withLiveConsent($cached, null, false));
        $this->assertSame('<p>other cPanel</p>', WhmChrome::withLiveConsent('<p>other cPanel</p>', 'on', true));
    }
}
