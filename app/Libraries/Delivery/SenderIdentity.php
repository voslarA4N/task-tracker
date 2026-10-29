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
     * Skutocne hodnoty v case odoslania: nastavenia subjektu, inak predvolene (D-27).
     *
     * @return array{from_email: string, from_name: string, reply_to: ?string, bcc: list<string>}
     */
    public function resolve(int $entityId): array
    {
        $row     = $this->db->query('SELECT mail_from_name, mail_from_email, mail_reply_to, mail_bcc FROM entities WHERE id = :id:', ['id' => $entityId])->getRowArray() ?? [];
        $default = $this->defaults($entityId);

        $name  = trim((string) ($row['mail_from_name'] ?? ''));
        $from  = trim((string) ($row['mail_from_email'] ?? ''));
        $reply = trim((string) ($row['mail_reply_to'] ?? ''));
        $bcc   = $default['bcc'];

        if (($row['mail_bcc'] ?? null) !== null) {
            $decoded = json_decode((string) $row['mail_bcc'], true);
            $bcc     = [];

            foreach (is_array($decoded) ? $decoded : [] as $item) {
                if (is_string($item) && $item !== '') {
                    $bcc[] = $item;
                }
            }
        }

        return [
            'from_email' => $from !== '' ? $from : $default['from_email'],
            'from_name'  => $name !== '' ? $name : $default['from_name'],
            'reply_to'   => $reply !== '' ? $reply : $default['reply_to'],
            'bcc'        => $bcc,
        ];
    }

    /**
     * Predvolene hodnoty D-27 (aj na predvyplnenie formulara).
     *
     * @return array{from_email: string, from_name: string, reply_to: ?string, bcc: list<string>}
     */
    public function defaults(int $entityId): array
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
