<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

/**
 * Vstup odoslania faktury (nedoveryhodny vstup z formulara; validuje ho InvoiceMailer::send).
 */
final readonly class SendCommand
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    public function __construct(
        public int $invoiceId,
        public string $kind,
        public array $to,
        public array $cc,
        public string $subject,
        public string $body,
        public string $requestKey,
        public ?int $userId,
    ) {
    }
}
