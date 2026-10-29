<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

/**
 * Jedna odchadzajuca sprava (D-03): vsetko, co AppMailer::sendMessage potrebuje.
 */
final readonly class OutgoingMessage
{
    /**
     * @param list<string>                                           $to
     * @param list<string>                                           $cc
     * @param list<string>                                           $bcc
     * @param list<array{name: string, bytes: string, mime: string}> $attachments
     */
    public function __construct(
        public string $kind,
        public string $fromEmail,
        public string $fromName,
        public ?string $replyTo,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $textBody,
        public string $htmlBody,
        public array $attachments,
        public string $messageId,
        public ?string $inReplyTo,
    ) {
    }
}
