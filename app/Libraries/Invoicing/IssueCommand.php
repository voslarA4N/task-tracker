<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * Vstup vystavenia faktury (nedoveryhodny vstup z formulara). Konstruktor validuje tvar, aby sa do SQL
 * nikdy nedostalo nic ine nez kladne int id a UUID.
 */
final readonly class IssueCommand
{
    public const PAYMENT_METHODS = ['transfer', 'cash', 'card', 'other'];
    public const NOTE_MAX        = 500;

    /**
     * @param list<int> $partIds
     */
    public function __construct(
        public int $clientId,
        public string $billingMonth,
        public int $entityId,
        public string $issueDate,
        public string $deliveryDate,
        public string $dueDate,
        public array $partIds,
        public string $previewToken,
        public string $requestKey,
        public bool $confirmBackdate = false,
        public ?int $userId = null,
        public string $paymentMethod = 'transfer',
        public ?string $paymentNote = null,
    ) {
        if ($clientId < 1 || $entityId < 1) {
            throw new InvalidArgumentException('Invalid client or entity id.');
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $billingMonth) !== 1) {
            throw new InvalidArgumentException('Invalid billing month.');
        }

        foreach ([$issueDate, $deliveryDate, $dueDate] as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InvalidArgumentException('Invalid date.');
            }
        }

        if ($partIds === []) {
            throw new InvalidArgumentException('No parts.');
        }

        $sorted = $partIds;
        sort($sorted);

        foreach ($partIds as $i => $id) {
            if ($id < 1 || $id !== $sorted[$i] || ($i > 0 && $id === $partIds[$i - 1])) {
                throw new InvalidArgumentException('Part ids must be unique, positive and sorted.');
            }
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $previewToken) !== 1) {
            throw new InvalidArgumentException('Invalid preview token.');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $requestKey) !== 1) {
            throw new InvalidArgumentException('Invalid request key.');
        }

        if (! in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('Invalid payment method.');
        }

        if ($paymentNote !== null && (mb_strlen($paymentNote) < 1 || mb_strlen($paymentNote) > self::NOTE_MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $paymentNote) === 1)) {
            throw new InvalidArgumentException('Invalid payment note.');
        }
    }
}
