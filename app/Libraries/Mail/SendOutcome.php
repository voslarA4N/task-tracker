<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

/**
 * Vysledok odoslania: transport ('smtp'|'log') a orezana odpoved SMTP servera.
 */
final readonly class SendOutcome
{
    public function __construct(
        public bool $ok,
        public string $transport,
        public ?string $smtpResponse,
    ) {
    }
}
