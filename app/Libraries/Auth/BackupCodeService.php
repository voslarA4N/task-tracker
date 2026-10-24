<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

/**
 * Zalozne kody 2FA (AUTH-03): v DB iba HMAC-SHA256 s pepperom, jednorazove, atomicka spotreba.
 */
final class BackupCodeService
{
    public const COUNT = 10;

    /**
     * Dlzka kodu podla rozhodnutia O-2 z 02-01-SUMMARY (10 znakov, format XXXXX-XXXXX).
     */
    public const LENGTH = 10;

    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * Zmaze stare kody a vytvori nove; vrati formatovane kody (jediny okamih, kedy existuju v otvorenom tvare).
     *
     * @return list<string>
     */
    public function generate(int $userId, DateTimeImmutable $now): array
    {
        $pepper = $this->pepper();
        $db     = $this->db();
        $codes  = [];
        $max    = strlen(self::ALPHABET) - 1;

        while (count($codes) < self::COUNT) {
            $raw = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $raw .= self::ALPHABET[random_int(0, $max)];
            }

            $codes[$raw] = $raw;
        }

        $db->transStart();
        $db->query('DELETE FROM backup_codes WHERE user_id = :u:', ['u' => $userId]);

        foreach ($codes as $raw) {
            $db->query(
                'INSERT INTO backup_codes (user_id, code_hash, created_at) VALUES (:u:, :h:, :now:)',
                ['u' => $userId, 'h' => $this->hash($raw, $pepper), 'now' => $now->format('Y-m-d H:i:sP')],
            );
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            throw new RuntimeException('Backup codes could not be stored.');
        }

        return array_values(array_map(self::format(...), $codes));
    }

    public function consume(int $userId, string $input, DateTimeImmutable $now): bool
    {
        $normalized = self::normalize($input);

        if ($normalized === '') {
            return false;
        }

        $db = $this->db();
        $db->query(
            'UPDATE backup_codes SET used_at = :now: WHERE user_id = :u: AND code_hash = :h: AND used_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:sP'), 'u' => $userId, 'h' => $this->hash($normalized, $this->pepper())],
        );

        return $db->affectedRows() === 1;
    }

    public function remaining(int $userId): int
    {
        $row = $this->db()->query('SELECT count(*) AS c FROM backup_codes WHERE user_id = :u: AND used_at IS NULL', ['u' => $userId])->getRow();

        return (int) $row->c;
    }

    public function deleteAll(int $userId): void
    {
        $this->db()->query('DELETE FROM backup_codes WHERE user_id = :u:', ['u' => $userId]);
    }

    public static function normalize(string $input): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($input));
    }

    public static function format(string $raw): string
    {
        return implode('-', str_split($raw, 5));
    }

    private function hash(string $normalized, string $pepper): string
    {
        return hash_hmac('sha256', $normalized, $pepper);
    }

    private function pepper(): string
    {
        $pepper = config('Auth')->backupCodePepper;

        if (! is_string($pepper) || $pepper === '') {
            throw new RuntimeException('auth.backupCodePepper is not configured.');
        }

        return $pepper;
    }

    /**
     * @return BaseConnection<mixed, mixed>
     */
    private function db(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
