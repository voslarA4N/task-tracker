<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Invoicing\PdfFormat;

/**
 * Vykreslenie sablon e-mailov (D-13, D-15). Iba strtr nad whitelistom placeholderov: ziadny eval, ziadny callback,
 * hodnoty sa nerozbaluju druhy raz, predmet nikdy neobsahuje CR/LF.
 */
final class TemplateRenderer
{
    public const PLACEHOLDERS = [
        'number', 'total', 'balance', 'currency', 'issue_date', 'due_date', 'billing_month',
        'variable_symbol', 'iban', 'client_name', 'entity_name', 'days_overdue',
    ];

    /**
     * @param array<string, string> $values
     *
     * @return array{subject: string, text: string}
     */
    public function render(string $subject, string $body, array $values): array
    {
        $map = [];

        foreach (self::PLACEHOLDERS as $key) {
            $map['{{' . $key . '}}'] = (string) ($values[$key] ?? '');
        }

        $subjectOut = (string) preg_replace('/[\r\n]+/', ' ', strtr($subject, $map));
        $textOut    = str_replace(["\r\n", "\r"], "\n", strtr($body, $map));

        return ['subject' => $subjectOut, 'text' => $textOut];
    }

    /**
     * Vzorove hodnoty pre nahlad sablony (bez vazby na skutocny doklad).
     *
     * @return array<string, string>
     */
    public static function sampleValues(string $language): array
    {
        $total = PdfFormat::amount('1080.00', 'EUR', $language);

        return [
            'number'          => '202610001',
            'total'           => $total,
            'balance'         => $total,
            'currency'        => 'EUR',
            'issue_date'      => PdfFormat::date('2026-10-01', $language),
            'due_date'        => PdfFormat::date('2026-10-15', $language),
            'billing_month'   => '09/2026',
            'variable_symbol' => '202610001',
            'iban'            => 'SK3112000000198742637541',
            'client_name'     => 'Vzorový klient s.r.o.',
            'entity_name'     => 'Vzorový subjekt',
            'days_overdue'    => '12',
        ];
    }

    public static function html(string $text): string
    {
        $text       = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = preg_split('/\n{2,}/', trim($text)) ?: [];
        $out        = '';

        foreach ($paragraphs as $p) {
            $out .= '<p>' . nl2br(esc($p), false) . '</p>';
        }

        return '<!DOCTYPE html><html><body>' . $out . '</body></html>';
    }

    /**
     * @return list<string>
     */
    public static function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $m);

        return array_values(array_unique(array_filter($m[1], static fn (string $n): bool => ! in_array($n, self::PLACEHOLDERS, true))));
    }
}
