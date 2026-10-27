<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Language\Language;

/**
 * Testovacie prepisanie prekladovych klucov (D-24): mapa 'Subor.kluc' => hodnota.
 */
final class OverridingLanguage extends Language
{
    /**
     * @param array<string, string> $overrides
     */
    public function __construct(string $locale, private readonly array $overrides)
    {
        parent::__construct($locale);
    }

    /**
     * @param array<array-key, mixed> $args
     *
     * @return list<string>|string
     */
    public function getLine(string $line, array $args = [])
    {
        if (isset($this->overrides[$line])) {
            return $this->overrides[$line];
        }

        return parent::getLine($line, $args);
    }
}
