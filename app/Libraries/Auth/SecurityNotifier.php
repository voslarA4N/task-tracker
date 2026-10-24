<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Libraries\Mail\AppMailer;
use DateTimeInterface;
use IntlDateFormatter;
use InvalidArgumentException;
use Throwable;

/**
 * Bezpecnostne upozornenia (D-13): iba text, bez odkazov a tajomstiev.
 * Chyba odoslania nikdy nevyhodi vynimku (nesmie zablokovat prihlasenie).
 */
final class SecurityNotifier
{
    public const PASSWORD_CHANGED         = 'password_changed';
    public const PASSWORD_RESET           = 'password_reset';
    public const TOTP_RESET               = 'totp_reset';
    public const BACKUP_CODES_REGENERATED = 'backup_codes_regenerated';
    public const DEVICE_TRUSTED           = 'device_trusted';
    public const ACCOUNT_LOCKED           = 'account_locked';
    private const KINDS                   = [
        self::PASSWORD_CHANGED,
        self::PASSWORD_RESET,
        self::TOTP_RESET,
        self::BACKUP_CODES_REGENERATED,
        self::DEVICE_TRUSTED,
        self::ACCOUNT_LOCKED,
    ];

    private readonly AppMailer $mailer;

    public function __construct(?AppMailer $mailer = null)
    {
        $this->mailer = $mailer ?? new AppMailer();
    }

    /**
     * @param array<string, mixed> $context povolene kluce: at, ip, device, minutes
     */
    public function notify(string $kind, string $email, string $locale, array $context = []): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown security notice kind: ' . $kind);
        }

        try {
            $at = $context['at'] ?? null;

            $body = view('emails/security_notice', [
                'kind'    => $kind,
                'locale'  => $locale,
                'at'      => $at instanceof DateTimeInterface ? $this->formatTime($at, $locale) : null,
                'ip'      => is_string($context['ip'] ?? null) ? $context['ip'] : null,
                'device'  => is_string($context['device'] ?? null) ? $context['device'] : null,
                'minutes' => is_int($context['minutes'] ?? null) ? $context['minutes'] : null,
            ], ['saveData' => false]);

            $subjectKey = 'Notify.subject_' . $kind; // dynamicky kluc, parita sk/en je v LangKeysTest
            $this->mailer->send($email, lang($subjectKey, [], $locale), $body, 'security.' . $kind);
        } catch (Throwable $e) {
            log_message('error', sprintf('mail.failed kind=security.%s reason=%s', $kind, $e::class));
        }
    }

    private function formatTime(DateTimeInterface $at, string $locale): string
    {
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            config('App')->appTimezone,
            IntlDateFormatter::GREGORIAN,
            'd. M. yyyy HH:mm',
        );

        $out = $formatter->format($at);

        return $out === false ? $at->format('Y-m-d H:i') : $out;
    }
}
