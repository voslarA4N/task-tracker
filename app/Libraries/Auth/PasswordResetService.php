<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Tokeny obnovy hesla (AUTH-06): 256-bitovy nahodny token, v DB iba sha256, 60 minut, jednorazovy.
 */
final class PasswordResetService
{
    public const TTL_MINUTES = 60;
    private const FORMAT     = 'Y-m-d H:i:sP';

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * Vrati otvoreny token iba pre existujuci aktivny ucet, inak null. Stare nepouzite tokeny pouzivatela zmaze.
     */
    public function request(string $email, string $ip, DateTimeImmutable $now): ?string
    {
        $email = mb_strtolower(trim($email));
        $db    = $this->db();

        $user = $db->query(
            "SELECT u.id FROM users u
             JOIN auth_identities i ON i.user_id = u.id AND i.type = 'email_password'
             WHERE i.secret = :e: AND u.active = 1 AND u.deleted_at IS NULL",
            ['e' => $email],
        )->getRow();

        if ($user === null) {
            return null;
        }

        $token  = bin2hex(random_bytes(32));
        $userId = (int) $user->id;

        $db->transStart();
        $db->query('DELETE FROM password_resets WHERE user_id = :u: AND used_at IS NULL', ['u' => $userId]);
        $db->query(
            'INSERT INTO password_resets (user_id, token_hash, ip, created_at, expires_at) VALUES (:u:, :h:, :ip:, :now:, :exp:)',
            [
                'u'   => $userId,
                'h'   => hash('sha256', $token),
                'ip'  => $ip,
                'now' => $now->format(self::FORMAT),
                'exp' => $now->setTimestamp($now->getTimestamp() + self::TTL_MINUTES * 60)->format(self::FORMAT),
            ],
        );
        $db->transComplete();

        return $db->transStatus() ? $token : null;
    }

    public function findValid(string $token, DateTimeImmutable $now): ?int
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $row = $this->db()->query(
            'SELECT r.user_id FROM password_resets r
             JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = :h: AND r.used_at IS NULL AND r.expires_at > :now:
               AND u.active = 1 AND u.deleted_at IS NULL',
            ['h' => hash('sha256', $token), 'now' => $now->format(self::FORMAT)],
        )->getRow();

        return $row === null ? null : (int) $row->user_id;
    }

    /**
     * Atomicka spotreba: dva sucasne pozadavky nikdy nespotrebuju ten isty token.
     */
    public function consume(string $token, DateTimeImmutable $now): ?int
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $row = $this->db()->query(
            'UPDATE password_resets SET used_at = :now:
             WHERE token_hash = :h: AND used_at IS NULL AND expires_at > :now:
             RETURNING user_id',
            ['h' => hash('sha256', $token), 'now' => $now->format(self::FORMAT)],
        )->getRow();

        return $row === null ? null : (int) $row->user_id;
    }

    /**
     * @return BaseConnection<mixed, mixed>
     */
    private function db(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
