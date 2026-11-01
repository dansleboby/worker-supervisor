<?php

declare(strict_types=1);

namespace WorkerSupervisor;

/**
 * The plugin's own message catalogs (lang/<locale>.php): cPanel's maketext is not reachable from
 * PHP. Messages use {name} placeholders; app.js applies the same rule to the catalog it receives.
 */
final class I18n
{
    private readonly string $locale;

    /** @var array<string, string> */
    private readonly array $messages;

    /** @param string $locale a cPanel locale (en, fr, fr_ca, ...); anything not French gets English */
    public function __construct(string $langDir, string $locale)
    {
        $this->locale = str_starts_with(strtolower($locale), 'fr') ? 'fr' : 'en';
        $this->messages = require $langDir . '/' . $this->locale . '.php';
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, scalar> $params */
    public function t(string $key, array $params = []): string
    {
        $text = $this->messages[$key] ?? $key;
        if ($params === []) {
            return $text;
        }

        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs['{' . $name . '}'] = (string) $value;
        }

        // strtr substitutes in one pass, so a value containing "{x}" is never expanded itself.
        return strtr($text, $pairs);
    }

    /** @return array<string, string> */
    public function catalog(): array
    {
        return $this->messages;
    }
}
