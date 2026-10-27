<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

/**
 * Nahlad faktury (D-02): to, co by sa vystavilo teraz, bez cisla a bez zapisu.
 */
final readonly class Draft
{
    /**
     * @param list<array<string, mixed>>                                                     $lines
     * @param list<int>                                                                      $partIds
     * @param array<string, mixed>|null                                                      $snapshot
     * @param array{number: string, issue_date: string}|null                                 $backdate
     * @param list<array{reason: string, context: array<string, mixed>}>                     $errors
     * @param list<array{id: int, name_internal: string, country: string, currency: string}> $entities aktivne k datumu vystavenia
     */
    public function __construct(
        public int $clientId,
        public string $month,
        public ?int $entityId,
        public string $issueDate,
        public string $deliveryDate,
        public string $dueDate,
        public array $lines,
        public string $total,
        public string $hours,
        public int $lineCount,
        public ?string $currency,
        public array $partIds,
        public ?string $previewToken,
        public ?array $snapshot,
        public ?array $backdate,
        public bool $hasLockedInMonth,
        public array $errors,
        public array $entities,
        public bool $canIssue,
        public string $paymentMethod = 'transfer',
        public ?string $paymentNote = null,
        public bool $paymentNoteDirty = false,
        public bool $provisionalText = false,
    ) {
    }
}
