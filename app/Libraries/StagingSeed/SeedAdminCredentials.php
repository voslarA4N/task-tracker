<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

/**
 * Prihlasovacie udaje seed admina (D-02). Citane zo suboru KEY=VALUE bez zapisu do prostredia procesu.
 * Chybove spravy nesu iba nazvy klucov, nikdy hodnoty.
 * SEED_ADMIN_TOTP_SECRET sa od 2026-10-28 ignoruje (2FA je volitelna, seed admin ju nema); riadok v starom subore nevadi.
 */
final readonly class SeedAdminCredentials
{
    public const KEY_EMAIL    = 'SEED_ADMIN_EMAIL';
    public const KEY_PASSWORD = 'SEED_ADMIN_PASSWORD';

    /**
     * Tolerovany a ignorovany kluc zo starsich suborov.
     */
    public const KEY_SECRET = 'SEED_ADMIN_TOTP_SECRET';

    private function __construct(
        public string $email,
        public string $password,
    ) {
    }

    /**
     * @param array<string, string>|null $fallback nahrada za env() v dev/testing (null = env())
     *
     * @throws SeedRefused
     */
    public static function load(string $file, string $environment, ?array $fallback = null): self
    {
        $values = [];

        if (is_file($file) && is_readable($file)) {
            $values = self::parse((string) file_get_contents($file));
        } elseif ($environment === 'production') {
            throw new SeedRefused(SeedRefused::CREDENTIALS, 'credentials file is missing');
        } else {
            foreach ([self::KEY_EMAIL, self::KEY_PASSWORD] as $key) {
                $value = $fallback === null ? env($key) : ($fallback[$key] ?? null);

                if (is_string($value)) {
                    $values[$key] = $value;
                }
            }
        }

        foreach ([self::KEY_EMAIL, self::KEY_PASSWORD] as $key) {
            if (($values[$key] ?? '') === '') {
                throw new SeedRefused(SeedRefused::CREDENTIALS, 'missing ' . $key);
            }
        }

        $email = mb_strtolower(trim($values[self::KEY_EMAIL]));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SeedRefused(SeedRefused::CREDENTIALS, 'invalid ' . self::KEY_EMAIL);
        }

        return new self($email, $values[self::KEY_PASSWORD]);
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $content): array
    {
        $out = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key           = trim($key);
            $value         = trim($value);
            $len           = strlen($value);

            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
