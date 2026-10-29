<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use App\Libraries\Platform\Uuid;
use Closure;
use Config\Email as EmailConfig;
use LogicException;

/**
 * Spolocna cesta odosielania e-mailov. Pri transporte 'log' (staging, D-14, O-3)
 * sa nic neposle a do logu idu iba metadata (druh, prijemca, predmet), nikdy telo.
 */
final class AppMailer
{
    /**
     * @var Closure(EmailConfig): AppEmail
     */
    private readonly Closure $emailFactory;

    /**
     * @param (Closure(EmailConfig): AppEmail)|null $emailFactory
     */
    public function __construct(?Closure $emailFactory = null)
    {
        $this->emailFactory = $emailFactory ?? static fn (EmailConfig $c): AppEmail => new AppEmail($c);
    }

    /**
     * Jedina cesta pre zlozene spravy (D-03): prilohy z bufferu, CC, BCC, Reply-To, vlastny Message-ID.
     * Kazda sprava ide cez novu instanciu AppEmail, aby sa prilohy nikdy nemiesali.
     */
    public function sendMessage(OutgoingMessage $m): SendOutcome
    {
        /** @var EmailConfig $config */
        $config = config('Email');

        if ($config->transport === 'log') {
            log_message('info', sprintf(
                'mail.suppressed kind=%s to=%s cc=%s subject=%s',
                $m->kind,
                $this->clean(implode(',', $m->to)),
                $this->clean(implode(',', $m->cc)),
                $this->clean($m->subject),
            ));

            return new SendOutcome(true, 'log', null);
        }

        if ($config->protocol !== 'smtp') {
            throw new LogicException('sendMessage vyzaduje email.protocol = smtp (BCC by inak isla do hlaviciek).');
        }

        $email = ($this->emailFactory)($config);
        $email->setMailType('html');
        $email->setFrom($m->fromEmail, $m->fromName);
        $email->setEnvelopeFrom($this->envelopeAddress($config));

        if ($m->replyTo !== null) {
            $email->setReplyTo($m->replyTo);
        }

        $email->setTo($m->to);

        if ($m->cc !== []) {
            $email->setCC(implode(',', $m->cc));
        }

        if ($m->bcc !== []) {
            $email->setBCC(implode(',', $m->bcc));
        }

        $email->setSubject($m->subject);
        $email->setMessage($m->htmlBody);
        $email->setAltMessage($m->textBody);
        $email->setMessageId($m->messageId);

        if ($m->inReplyTo !== null) {
            $email->setHeader('In-Reply-To', $m->inReplyTo);
            $email->setHeader('References', $m->inReplyTo);
        }

        foreach ($m->attachments as $a) {
            $email->attach($a['bytes'], 'attachment', $a['name'], $a['mime']);
        }

        if ($email->send(false) === false) {
            log_message('error', sprintf('mail.failed kind=%s to=%s', $m->kind, $this->clean(implode(',', $m->to))));

            return new SendOutcome(false, 'smtp', $this->reply($email));
        }

        return new SendOutcome(true, 'smtp', $this->reply($email));
    }

    /**
     * Novy Message-ID v tvare <uuid@domena-obalky> (D-12).
     */
    public function messageIdFor(): string
    {
        /** @var EmailConfig $config */
        $config = config('Email');
        $domain = substr(strrchr($this->envelopeAddress($config), '@') ?: '@localhost', 1);

        return '<' . Uuid::v4() . '@' . $domain . '>';
    }

    private function envelopeAddress(EmailConfig $config): string
    {
        return $config->envelopeFrom !== '' ? $config->envelopeFrom : $config->fromEmail;
    }

    private function reply(AppEmail $email): ?string
    {
        $reply = mb_substr($this->clean($email->smtpReply() ?? ''), 0, 500);

        return $reply !== '' ? $reply : null;
    }

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
