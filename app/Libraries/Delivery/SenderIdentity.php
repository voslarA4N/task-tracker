<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Master\EntityVersionResolver;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Odosielatel e-mailu (D-12, D-27 predvolene hodnoty): From z konfiguracie, meno a Reply-To zo subjektu,
 * BCC archiv na e-mail subjektu. From musi byt v povolenej domene (T-07-13b).
 */
final class SenderIdentity
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{from_email: string, from_name: string, reply_to: ?string, bcc: list<string>}
     */
    public function resolve(int $entityId): array
    {
        $version = (new EntityVersionResolver($this->db))->current($entityId, service('clock')->today());
        $email   = trim((string) ($version['email'] ?? ''));

        return [
            'from_email' => (string) config('Email')->fromEmail,
            'from_name'  => (string) $version['trade_name'],
            'reply_to'   => $email !== '' ? $email : null,
            'bcc'        => $email !== '' ? [$email] : [],
        ];
    }

    /**
     * @throws SenderRejected
     */
    public static function assertAllowed(string $email): void
    {
        $at = strrpos($email, '@');

        if ($at === false
            || preg_match('/[\x00-\x1F\x7F]/', $email) === 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SenderRejected('invalid');
        }

        $domain = mb_strtolower(substr($email, $at + 1));

        if (! in_array($domain, self::allowedDomains(), true)) {
            throw new SenderRejected('domain');
        }
    }

    /**
     * @return list<string>
     */
    public static function allowedDomains(): array
    {
        $out = [];

        foreach (explode(',', (string) config('Email')->allowedFromDomains) as $d) {
            $d = mb_strtolower(trim($d));

            if ($d !== '' && ! in_array($d, $out, true)) {
                $out[] = $d;
            }
        }

        return $out;
    }
}
