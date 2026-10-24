<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Obmedzenie hádania hesla (AUTH-04, D-19): 5 pokusov / 15 min pre IP aj účet, zámok 15 min.
 * Čas sa berie iba z parametra, zámok sa počas trvania nepredlžuje.
 */
final class LockoutService
{
    public const MAX_FAILS      = 5;
    public const WINDOW_MINUTES = 15;
    public const LOCK_MINUTES   = 15;
    private const TS_FORMAT     = 'Y-m-d H:i:sP';

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

    public function lockedUntil(string $ip, string $email, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $rows = $this->db->query(
            'SELECT locked_until FROM auth_lockouts WHERE ((kind = \'ip\' AND subject = :ip:) OR (kind = \'account\' AND subject = :acc:)) AND locked_until > :now:',
            ['ip' => $this->ipSubject($ip), 'acc' => $this->accountSubject($email), 'now' => $now->format(self::TS_FORMAT)],
        )->getResultArray();

        $latest = null;

        foreach ($rows as $row) {
            $until = $now->modify((string) $row['locked_until'])->setTimezone($now->getTimezone());

            if ($latest === null || $until > $latest) {
                $latest = $until;
            }
        }

        return $latest;
    }

    /**
     * @return bool true, ak práve teraz začal zámok účtu
     */
    public function registerFailure(string $ip, string $email, DateTimeImmutable $now): bool
    {
        $account   = $this->accountSubject($email);
        $wasLocked = $this->isAccountLocked($account, $now);

        foreach ([['ip', $this->ipSubject($ip)], ['account', $account]] as [$kind, $subject]) {
            $this->upsert($kind, $subject, $now);
        }

        return ! $wasLocked && $this->isAccountLocked($account, $now);
    }

    public function clear(string $ip, string $email): void
    {
        $this->db->query(
            'DELETE FROM auth_lockouts WHERE (kind = \'ip\' AND subject = :ip:) OR (kind = \'account\' AND subject = :acc:)',
            ['ip' => $this->ipSubject($ip), 'acc' => $this->accountSubject($email)],
        );
    }

    public function ipSubject(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $masked = substr($packed, 0, 8) . str_repeat("\0", 8);

        return inet_ntop($masked) . '/64';
    }

    public function accountSubject(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private function isAccountLocked(string $subject, DateTimeImmutable $now): bool
    {
        $row = $this->db->query(
            'SELECT 1 AS x FROM auth_lockouts WHERE kind = \'account\' AND subject = :s: AND locked_until > :now:',
            ['s' => $subject, 'now' => $now->format(self::TS_FORMAT)],
        )->getRow();

        return $row !== null;
    }

    private function upsert(string $kind, string $subject, DateTimeImmutable $now): void
    {
        $this->db->query(
            <<<'SQL'
                INSERT INTO auth_lockouts AS l (kind, subject, fail_count, window_start, locked_until)
                VALUES (:kind:, :subject:, 1, :now:, NULL)
                ON CONFLICT (kind, subject) DO UPDATE SET
                    fail_count = CASE
                        WHEN l.locked_until IS NOT NULL AND l.locked_until > :now: THEN l.fail_count
                        WHEN l.locked_until IS NOT NULL OR l.window_start < :window_floor: THEN 1
                        ELSE l.fail_count + 1
                    END,
                    window_start = CASE
                        WHEN l.locked_until IS NOT NULL AND l.locked_until > :now: THEN l.window_start
                        WHEN l.locked_until IS NOT NULL OR l.window_start < :window_floor: THEN CAST(:now: AS timestamptz)
                        ELSE l.window_start
                    END,
                    locked_until = CASE
                        WHEN l.locked_until IS NOT NULL AND l.locked_until > :now: THEN l.locked_until
                        WHEN l.locked_until IS NULL AND l.window_start >= :window_floor: AND l.fail_count + 1 >= :max: THEN CAST(:lock_until: AS timestamptz)
                        ELSE NULL
                    END
                SQL,
            [
                'kind'         => $kind,
                'subject'      => $subject,
                'now'          => $now->format(self::TS_FORMAT),
                'window_floor' => $now->modify('-' . self::WINDOW_MINUTES . ' minutes')->format(self::TS_FORMAT),
                'lock_until'   => $now->modify('+' . self::LOCK_MINUTES . ' minutes')->format(self::TS_FORMAT),
                'max'          => self::MAX_FAILS,
            ],
        );
    }
}
