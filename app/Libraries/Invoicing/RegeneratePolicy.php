<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Platform\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Kto, čo a kedy sa smie pregenerovať. Jedna čistá funkcia pre server aj UI.
 * Lehota PDF faktúry je zmrazená pri vystavení (invoice_regen_windows), volajúci ju dodá v kľúči regen_until.
 */
final class RegeneratePolicy
{
    public const KINDS = ['invoice', 'report'];

    /**
     * @param array<string, mixed> $invoice
     */
    public static function check(array $invoice, string $kind, DateTimeImmutable $now): ?string
    {
        if (! in_array($kind, self::KINDS, true)) {
            return 'not_regenerable';
        }

        if (($invoice['origin'] ?? null) !== 'issued') {
            return 'not_regenerable';
        }

        $type   = (string) ($invoice['type'] ?? '');
        $schema = (int) ($invoice['snapshot_schema'] ?? 0);

        if ($kind === 'report') {
            return $type === 'invoice' && $schema >= 2 ? null : 'not_regenerable';
        }

        if ($type !== 'credit_note' && $schema < 2) {
            return 'not_regenerable';
        }

        $deadline = self::deadline($invoice);

        if ($deadline === null || $now > $deadline) {
            return 'window_expired';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public static function canRegenerate(array $invoice, string $kind, DateTimeImmutable $now): bool
    {
        return self::check($invoice, $kind, $now) === null;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public static function assertAllowed(array $invoice, string $kind, DateTimeImmutable $now): void
    {
        $reason = self::check($invoice, $kind, $now);

        if ($reason !== null) {
            throw new RegenerateRejected($reason, ['kind' => $kind]);
        }
    }

    /**
     * Koniec lehoty zmrazeny pri vystaveni (kluc regen_until, doplna ho InvoiceRegenWindows::attach).
     * Null = lehota je uzavreta: chyba regen_until alebo created_at, neplatny cas, alebo lehota 0 (regen_until nie je po created_at).
     *
     * @param array<string, mixed> $invoice
     */
    public static function deadline(array $invoice): ?DateTimeImmutable
    {
        $until   = $invoice['regen_until'] ?? null;
        $created = $invoice['created_at'] ?? null;

        if (! is_string($until) || $until === '' || ! is_string($created) || $created === '') {
            return null;
        }

        try {
            $deadline = Clock::parseInstant($until);

            return $deadline > Clock::parseInstant($created) ? $deadline : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
