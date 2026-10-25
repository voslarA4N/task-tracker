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

    /**
     * Najviac tokenov (e-mailov) za hodinu na ucet a na IP (WR-03); dalsie poziadavky sa ticho zahodia.
     */
    public const MAX_PER_ACCOUNT_HOUR = 3;

    public const MAX_PER_IP_HOUR = 5;
    private const FORMAT         = 'Y-m-d H:i:sP';

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * Vrati otvoreny token iba pre existujuci aktivny ucet s aktivnym TOTP, inak null; null aj po prekroceni limitu.
     * Ucet bez 2FA by po resete presiel rovno na nastavenie noveho autentifikatora, teda by e-mail
     * stacil na prevzatie uctu (WR-04); takyto ucet obnovi admin cez `spark user:password` (D-14).
     * Platne skorsie tokeny ostavaju platne (WR-03: cudzia poziadavka nezneplatni odkaz vlastnika),
     * vsetky nepouzite zmaze az uspesna zmena hesla (AccountRecovery::setPassword).
     */
    public function request(string $email, string $ip, DateTimeImmutable $now): ?string
    {
        $email = mb_strtolower(trim($email));
        $db    = $this->db();

        $user = $db->query(
            "SELECT u.id FROM users u
             JOIN auth_identities i ON i.user_id = u.id AND i.type = 'email_password'
             JOIN user_security s ON s.user_id = u.id AND s.totp_enabled_at IS NOT NULL
             WHERE i.secret = :e: AND u.active = 1 AND u.deleted_at IS NULL",
            ['e' => $email],
        )->getRow();

        if ($user === null) {
            return null;
        }

        $token  = bin2hex(random_bytes(32));
        $userId = (int) $user->id;
        $since  = $now->setTimestamp($now->getTimestamp() - 3600)->format(self::FORMAT);
        $issued = false;

        $db->transStart();
        // Subezne poziadavky pre ten isty ucet sa zoradia, limit sa neda prekrocit subehom.
        $db->query("SELECT pg_advisory_xact_lock(hashtext('password_resets'), :u:)", ['u' => $userId]);
        $db->query('DELETE FROM password_resets WHERE user_id = :u: AND used_at IS NULL AND expires_at <= :now:', ['u' => $userId, 'now' => $now->format(self::FORMAT)]);

        $perAccount = (int) $db->query('SELECT count(*) AS c FROM password_resets WHERE user_id = :u: AND created_at > :since:', ['u' => $userId, 'since' => $since])->getRow()->c;
        $perIp      = (int) $db->query('SELECT count(*) AS c FROM password_resets WHERE ip = :ip: AND created_at > :since:', ['ip' => $ip, 'since' => $since])->getRow()->c;

        if ($perAccount < self::MAX_PER_ACCOUNT_HOUR && $perIp < self::MAX_PER_IP_HOUR) {
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
            $issued = true;
        }

        $db->transComplete();

        if (! $issued) {
            log_message('notice', sprintf('password_reset.throttled user=%d', $userId));

            return null;
        }

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
             JOIN user_security s ON s.user_id = r.user_id AND s.totp_enabled_at IS NOT NULL
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
               AND EXISTS (SELECT 1 FROM user_security s WHERE s.user_id = password_resets.user_id AND s.totp_enabled_at IS NOT NULL)
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
