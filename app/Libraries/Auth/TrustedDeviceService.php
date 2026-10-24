<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Dovernost zariadenia (D-15..D-17): token v cookie `__Host-wt_device`, v DB iba sha256.
 * Expiracia je pevna (30 dni od zadania TOTP), pouzitie ju nepredlzuje.
 */
final class TrustedDeviceService
{
    public const COOKIE_NAME             = 'wt_device';
    public const COOKIE_PREFIX           = '__Host-';
    public const TTL_DAYS                = 30;
    public const ROTATION_GRACE_SECONDS  = 60;
    private const TOUCH_INTERVAL_SECONDS = 3600;
    private const FORMAT                 = 'Y-m-d H:i:sP';

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @return array{id: int, token: string, expiresAt: DateTimeImmutable}
     */
    public function issue(int $userId, string $userAgent, string $ip, DateTimeImmutable $now): array
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = $now->setTimestamp($now->getTimestamp() + self::TTL_DAYS * 86400);
        $agent     = mb_substr($userAgent, 0, 512);

        $row = $this->db()->query(
            'INSERT INTO trusted_devices (user_id, token_hash, label, user_agent, ip_first, created_at, last_used_at, expires_at)
             VALUES (:u:, :h:, :label:, :ua:, :ip:, :now:, :now:, :exp:) RETURNING id',
            [
                'u'     => $userId,
                'h'     => hash('sha256', $token),
                'label' => DeviceLabel::fromUserAgent($agent),
                'ua'    => $agent === '' ? null : $agent,
                'ip'    => $ip,
                'now'   => $now->format(self::FORMAT),
                'exp'   => $expiresAt->format(self::FORMAT),
            ],
        )->getRow();

        return ['id' => (int) $row->id, 'token' => $token, 'expiresAt' => $expiresAt];
    }

    /**
     * @return array{id: int, user_id: int, expires_at: string, expires_ts: int}|null
     */
    public function findValid(string $token, DateTimeImmutable $now): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        $row = $this->db()->query(
            'SELECT d.id, d.user_id, d.expires_at, EXTRACT(EPOCH FROM d.expires_at)::bigint AS expires_ts
             FROM trusted_devices d
             JOIN users u ON u.id = d.user_id
             WHERE (d.token_hash = :h: OR (d.prev_token_hash = :h: AND d.rotated_at >= :floor:))
               AND d.revoked_at IS NULL AND d.expires_at > :now:
               AND u.active = 1 AND u.deleted_at IS NULL',
            [
                'h'     => hash('sha256', $token),
                'floor' => $now->setTimestamp($now->getTimestamp() - self::ROTATION_GRACE_SECONDS)->format(self::FORMAT),
                'now'   => $now->format(self::FORMAT),
            ],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'expires_at' => (string) $row['expires_at'], 'expires_ts' => (int) $row['expires_ts']];
    }

    /**
     * Vymeni token (expires_at sa nemeni). Vrati novy token, alebo '' ak `$oldToken` uz nie je aktualny
     * (stary token v toleranci pri suberznych poziadavkach: neotacat druhy raz).
     */
    public function rotate(int $deviceId, string $oldToken, DateTimeImmutable $now): string
    {
        $new = bin2hex(random_bytes(32));
        $old = hash('sha256', $oldToken);
        $db  = $this->db();

        $db->query(
            'UPDATE trusted_devices SET prev_token_hash = token_hash, token_hash = :new:, rotated_at = :now:, last_used_at = :now:
             WHERE id = :id: AND token_hash = :old:',
            ['new' => hash('sha256', $new), 'now' => $now->format(self::FORMAT), 'id' => $deviceId, 'old' => $old],
        );

        return $db->affectedRows() === 1 ? $new : '';
    }

    public function isActive(int $deviceId, int $userId, DateTimeImmutable $now): bool
    {
        $row = $this->db()->query(
            'SELECT 1 AS ok FROM trusted_devices WHERE id = :id: AND user_id = :u: AND revoked_at IS NULL AND expires_at > :now:',
            ['id' => $deviceId, 'u' => $userId, 'now' => $now->format(self::FORMAT)],
        )->getRow();

        return $row !== null;
    }

    public function touch(int $deviceId, DateTimeImmutable $now): void
    {
        $this->db()->query(
            'UPDATE trusted_devices SET last_used_at = :now: WHERE id = :id: AND last_used_at < :floor:',
            [
                'now'   => $now->format(self::FORMAT),
                'id'    => $deviceId,
                'floor' => $now->setTimestamp($now->getTimestamp() - self::TOUCH_INTERVAL_SECONDS)->format(self::FORMAT),
            ],
        );
    }

    public function revoke(int $deviceId, int $userId, string $reason, DateTimeImmutable $now): bool
    {
        $db = $this->db();
        $db->query(
            'UPDATE trusted_devices SET revoked_at = :now:, revoked_reason = :r: WHERE id = :id: AND user_id = :u: AND revoked_at IS NULL',
            ['now' => $now->format(self::FORMAT), 'r' => $reason, 'id' => $deviceId, 'u' => $userId],
        );

        return $db->affectedRows() === 1;
    }

    public function revokeAll(int $userId, string $reason, DateTimeImmutable $now): int
    {
        $db = $this->db();
        $db->query(
            'UPDATE trusted_devices SET revoked_at = :now:, revoked_reason = :r: WHERE user_id = :u: AND revoked_at IS NULL',
            ['now' => $now->format(self::FORMAT), 'r' => $reason, 'u' => $userId],
        );

        return $db->affectedRows();
    }

    /**
     * Neodvolane a neexpirovane zariadenia, najnovsie pouzite prve.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, DateTimeImmutable $now): array
    {
        return $this->db()->query(
            'SELECT id, label, ip_first, created_at, last_used_at, expires_at
             FROM trusted_devices
             WHERE user_id = :u: AND revoked_at IS NULL AND expires_at > :now:
             ORDER BY last_used_at DESC, id DESC',
            ['u' => $userId, 'now' => $now->format(self::FORMAT)],
        )->getResultArray();
    }

    public function currentEpoch(int $userId): int
    {
        $row = $this->db()->query('SELECT auth_epoch FROM user_security WHERE user_id = :u:', ['u' => $userId])->getRow();

        return $row === null ? 0 : (int) $row->auth_epoch;
    }

    public function bumpEpoch(int $userId, DateTimeImmutable $now): int
    {
        $row = $this->db()->query(
            'UPDATE user_security SET auth_epoch = auth_epoch + 1, updated_at = :now: WHERE user_id = :u: RETURNING auth_epoch',
            ['now' => $now->format(self::FORMAT), 'u' => $userId],
        )->getRow();

        return $row === null ? 0 : (int) $row->auth_epoch;
    }

    public function cookie(string $token, DateTimeImmutable $expiresAt): Cookie
    {
        return new Cookie(self::COOKIE_NAME, $token, [
            'expires'  => $expiresAt,
            'prefix'   => self::COOKIE_PREFIX,
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
            'path'     => '/',
            'domain'   => '',
            'raw'      => false,
        ]);
    }

    public function expiredCookie(): Cookie
    {
        return new Cookie(self::COOKIE_NAME, '', [
            'expires'  => 1,
            'prefix'   => self::COOKIE_PREFIX,
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
            'path'     => '/',
            'domain'   => '',
            'raw'      => false,
        ]);
    }

    /**
     * @return BaseConnection<mixed, mixed>
     */
    private function db(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
