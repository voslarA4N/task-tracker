<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

/**
 * Jednotny text kurzu pre nahlad, detail aj PDF (D-13, D-15): iba retazce, bez NumberFormatter.
 */
final class FxFormat
{
    public static function rate(string $published, string $language): string
    {
        return $language === 'en' ? $published : str_replace('.', ',', $published);
    }

    /**
     * @param array<string, mixed> $fx tvar FxQuote::core()
     *
     * @return array{amount: int, base: string, quote: string}
     */
    public static function parts(array $fx): array
    {
        if ($fx['direction'] === 'divide') {
            return ['amount' => 1, 'base' => (string) $fx['entity_currency'], 'quote' => (string) $fx['invoice_currency']];
        }

        return ['amount' => (int) $fx['amount'], 'base' => (string) $fx['invoice_currency'], 'quote' => (string) $fx['entity_currency']];
    }

    /**
     * @param array<string, mixed> $fx tvar FxQuote::core()
     */
    public static function expression(array $fx, string $language): string
    {
        $p = self::parts($fx);

        return $p['amount'] . ' ' . $p['base'] . ' = ' . self::rate((string) ($fx['rate_display'] ?? $fx['rate']), $language) . ' ' . $p['quote'];
    }

    public static function sourceLabel(string $source): string
    {
        return $source === 'CNB' ? 'ČNB' : $source;
    }
}
