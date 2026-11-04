<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Platform\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Kto, čo a kedy sa smie pregenerovať. Jedna čistá funkcia pre server aj UI.
 * Lehota sa meria od invoices.created_at, hodiny dodáva volajúci.
 */
final class RegeneratePolicy
{
    public const KINDS = ['invoice', 'report'];

    /**
     * @param array<string, mixed> $invoice
     */
    public static function check(array $invoice, string $kind, int $windowHours, DateTimeImmutable $now): ?string
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

        $deadline = self::deadline($invoice, $windowHours);

        if ($deadline === null || $now > $deadline) {
            return 'window_expired';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public static function canRegenerate(array $invoice, string $kind, int $windowHours, DateTimeImmutable $now): bool
    {
        return self::check($invoice, $kind, $windowHours, $now) === null;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public static function assertAllowed(array $invoice, string $kind, int $windowHours, DateTimeImmutable $now): void
    {
        $reason = self::check($invoice, $kind, $windowHours, $now);

        if ($reason !== null) {
            throw new RegenerateRejected($reason, ['kind' => $kind]);
        }
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public static function deadline(array $invoice, int $windowHours): ?DateTimeImmutable
    {
        if ($windowHours <= 0) {
            return null;
        }

        $createdAt = $invoice['created_at'] ?? null;

        if (! is_string($createdAt) || $createdAt === '') {
            return null;
        }

        try {
            return Clock::parseInstant($createdAt)->modify('+' . $windowHours . ' hours');
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
