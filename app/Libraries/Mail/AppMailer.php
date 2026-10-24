<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use Config\Email as EmailConfig;

/**
 * Spolocna cesta odosielania e-mailov. Pri transporte 'log' (staging, D-14, O-3)
 * sa nic neposle a do logu idu iba metadata (druh, prijemca, predmet), nikdy telo.
 */
final class AppMailer
{
    public function send(string $to, string $subject, string $body, string $kind): bool
    {
        /** @var EmailConfig $config */
        $config = config('Email');

        if ($config->transport === 'log') {
            log_message('info', sprintf('mail.suppressed kind=%s to=%s subject=%s', $kind, $this->clean($to), $this->clean($subject)));

            return true;
        }

        $email = service('email');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($body);

        if ($email->send() === false) {
            log_message('error', sprintf('mail.failed kind=%s to=%s', $kind, $this->clean($to)));

            return false;
        }

        return true;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }
}
