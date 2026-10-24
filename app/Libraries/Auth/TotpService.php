<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Libraries\Platform\Clock;
use App\Libraries\Platform\PsrClock;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Encryption\EncrypterInterface;
use Config\Database;
use Config\Services;
use OTPHP\TOTP;
use RuntimeException;

/**
 * TOTP (RFC 6238): generovanie tajomstva, QR, overenie v okne +-1 krok, sifrovanie tajomstva.
 * Tajomstvo ani provisioning URI sa nikdy nelogujú.
 */
final class TotpService
{
    public const PERIOD = 30;
    public const ISSUER = 'Work Tracker';

    private readonly Clock $clock;
    private readonly PsrClock $psrClock;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?Clock $clock = null, private readonly ?BaseConnection $db = null)
    {
        $this->clock    = $clock ?? service('clock');
        $this->psrClock = new PsrClock($this->clock);
    }

    public function newSecret(): string
    {
        return TOTP::generate($this->psrClock, 20)->getSecret();
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $totp = TOTP::createFromSecret($secret, $this->psrClock)
            ->withLabel($email)
            ->withIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    public function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 4), new SvgImageBackEnd()));
        $svg    = $writer->writeString($uri);

        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }

    /**
     * Vrati najvyssi zhodny casovy krok vacsi ako $lastStep, inak null. Bez predcasneho ukoncenia;
     * leeway otphp je v sekundach, preto vlastna slucka nad at().
     */
    public function matchStep(string $secret, string $code, ?int $lastStep): ?int
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return null;
        }

        $totp  = TOTP::createFromSecret($secret, $this->psrClock);
        $now   = $this->clock->now()->getTimestamp();
        $match = null;

        foreach ([-1, 0, 1] as $delta) {
            $ts = $now + $delta * self::PERIOD;

            if (hash_equals($totp->at($ts), $code)) {
                $step = intdiv($ts, self::PERIOD);

                if ($lastStep === null || $step > $lastStep) {
                    $match = max($match ?? $step, $step);
                }
            }
        }

        return $match;
    }

    /**
     * Atomicky zabrani krok (replay): true, ak sa podarilo posunut totp_last_step.
     */
    public function claimStep(int $userId, int $step): bool
    {
        $db = $this->db ?? Database::connect();
        $db->query(
            'UPDATE user_security SET totp_last_step = :s: WHERE user_id = :u: AND totp_enabled_at IS NOT NULL AND (totp_last_step IS NULL OR totp_last_step < :s:)',
            ['s' => $step, 'u' => $userId],
        );

        return $db->affectedRows() === 1;
    }

    public function encryptSecret(string $secret): string
    {
        return base64_encode($this->encrypter()->encrypt($secret));
    }

    public function decryptSecret(string $stored): string
    {
        $raw = base64_decode($stored, true);

        if ($raw === false) {
            throw new RuntimeException('TOTP tajomstvo nie je platné base64.');
        }

        return $this->encrypter()->decrypt($raw);
    }

    public function formatSecretForDisplay(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /**
     * SodiumHandler po pouziti nuluje kluc, preto novy handler pre kazde volanie.
     */
    private function encrypter(): EncrypterInterface
    {
        return Services::encrypter(null, false);
    }
}
