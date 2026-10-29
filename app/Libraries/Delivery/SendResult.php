<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

final readonly class SendResult
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    public function __construct(
        public int $sendId,
        public string $messageId,
        public string $transport,
        public array $to,
        public array $cc,
        public bool $countsAsSent,
        public bool $replay,
    ) {
    }
}
