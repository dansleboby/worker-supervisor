<?php

declare(strict_types=1);

namespace WorkerSupervisor\Tests\Unit;

use WorkerSupervisor\I18n;
use WorkerSupervisor\Issue;
use WorkerSupervisor\ServiceError;
use WorkerSupervisor\Templates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

final class I18nTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../lang';

    /** Every status StatusView::row can return. */
    private const STATUSES = [
        'running', 'starting', 'stopping', 'stopped', 'failed', 'suspended', 'admin_stopped', 'feature_off',
    ];

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            array_map('unlink', glob($this->tmp . '/*') ?: []);
            rmdir($this->tmp);
        }
    }

    public function test_french_has_every_english_key(): void
    {
        $en = (new I18n(self::LANG_DIR, 'en'))->catalog();
        $fr = (new I18n(self::LANG_DIR, 'fr'))->catalog();

        $this->assertSame(array_keys($en), array_keys($fr));
    }

    public function test_every_issue_code_has_a_message(): void
    {
        $codes = (new ReflectionClassConstant(Issue::class, 'CODES'))->getValue();

        $this->assertNotEmpty($codes);
        $this->assertHasMessages(array_map(static fn (string $c) => 'issue.' . $c, $codes));
    }

    public function test_every_error_code_has_a_message(): void
    {
        // feature_disabled comes from the UAPI module's own gate, not from ServiceError.
        $codes = [...ServiceError::CODES, 'feature_disabled'];

        $this->assertHasMessages(array_map(static fn (string $c) => 'error.' . $c, $codes));
    }

    public function test_every_status_and_reason_has_a_label(): void
    {
        $this->assertHasMessages([
            ...array_map(static fn (string $s) => 'status.' . $s, self::STATUSES),
            'reason.exited_too_quickly',
        ]);
    }

    public function test_every_template_has_a_name_and_deploy_hint(): void
    {
        $keys = [];
        foreach (Templates::all() as $t) {
            $keys[] = 'template.' . $t->id;
            $keys[] = 'deploy_hint.' . $t->id;
        }

        $this->assertHasMessages($keys);
    }

    #[DataProvider('locales')]
    public function test_locale_resolution(string $locale, string $expected): void
    {
        $this->assertSame($expected, (new I18n(self::LANG_DIR, $locale))->locale());
    }

    /** @return array<string, array{string, string}> */
    public static function locales(): array
    {
        return [
            'fr' => ['fr', 'fr'],
            'fr_ca' => ['fr_ca', 'fr'],
            'upper case' => ['FR_CA', 'fr'],
            'en' => ['en', 'en'],
            'other language' => ['de', 'en'],
            'empty' => ['', 'en'],
        ];
    }

    public function test_fr_locale_prefix(): void
    {
        $fr = new I18n(self::LANG_DIR, 'fr_ca');
        $en = new I18n(self::LANG_DIR, 'en');

        $this->assertSame('Arrêtée', $fr->t('status.stopped'));
        $this->assertSame('Stopped', $en->t('status.stopped'));
    }

    public function test_placeholders_are_replaced(): void
    {
        $en = new I18n(self::LANG_DIR, 'en');

        $this->assertSame('Exited too quickly 30 times.', $en->t('reason.exited_too_quickly', ['retries' => 30]));
    }

    public function test_replaced_values_are_not_expanded_again(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wsv-i18n-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        file_put_contents($this->tmp . '/en.php', "<?php return ['k' => '{a} and {b}'];");

        $this->assertSame('{b} and x', (new I18n($this->tmp, 'en'))->t('k', ['a' => '{b}', 'b' => 'x']));
    }

    public function test_unknown_key_returns_the_key(): void
    {
        $this->assertSame('no.such.key', (new I18n(self::LANG_DIR, 'en'))->t('no.such.key'));
    }

    /** @param string[] $keys */
    private function assertHasMessages(array $keys): void
    {
        foreach (['en', 'fr'] as $locale) {
            $catalog = (new I18n(self::LANG_DIR, $locale))->catalog();
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $catalog, "$locale is missing $key");
                $this->assertNotSame('', trim($catalog[$key]), "$locale has an empty $key");
            }
        }
    }
}
