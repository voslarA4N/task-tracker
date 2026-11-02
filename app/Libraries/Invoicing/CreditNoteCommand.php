<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use InvalidArgumentException;

/**
 * Vstup vystavenia dobropisu (nedoveryhodny vstup z formulara, D-20): iba id povodnej faktury, datum, dovod,
 * typ, token a request_key. Nikdy sumy, riadky ani kurz.
 */
final readonly class CreditNoteCommand
{
    public const REASON_MAX = 500;

    public string $reason;

    public function __construct(
        public int $originalId,
        public string $issueDate,
        string $reason,
        public CorrectionType $correctionType,
        public string $previewToken,
        public string $requestKey,
        public bool $confirmBackdate = false,
        public ?int $userId = null,
    ) {
        if ($originalId < 1) {
            throw new InvalidArgumentException('Invalid original invoice id.');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $issueDate, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Invalid date.');
        }

        $reason = trim($reason);
        $len    = mb_strlen($reason);

        if ($len < 1 || $len > self::REASON_MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('Invalid reason.');
        }

        $this->reason = $reason;

        if (preg_match('/^[0-9a-f]{64}$/D', $previewToken) !== 1) {
            throw new InvalidArgumentException('Invalid preview token.');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $requestKey) !== 1) {
            throw new InvalidArgumentException('Invalid request key.');
        }
    }
}
