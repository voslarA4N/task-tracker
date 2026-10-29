<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Delivery\InvoiceMailer;
use App\Libraries\Delivery\SendCommand;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Mail\AppEmail;
use App\Libraries\Mail\AppMailer;
use Config\Email as EmailConfig;

/**
 * Fixtures odosielania faktur (Phase 7): konfiguracia e-mailu, AppMailer s CapturingEmail, vystavena faktura.
 * Pouziva IssueFixtures a MasterFixtures.
 *
 * @mixin PgTestCase
 */
trait DeliveryFixtures
{
    protected ?CapturingEmail $lastEmail = null;
    protected int $emailFactoryCalls     = 0;
    protected bool $nextEmailResult      = true;

    protected function configureMail(string $transport = 'smtp'): void
    {
        $config               = config('Email');
        $config->transport    = $transport;
        $config->protocol     = 'smtp';
        $config->fromEmail    = 'noreply@vostack.dev';
        $config->envelopeFrom = 'noreply@vostack.dev';

        $this->lastEmail         = null;
        $this->emailFactoryCalls = 0;
        $this->nextEmailResult   = true;
    }

    protected function resetMail(): void
    {
        $config               = config('Email');
        $config->transport    = 'smtp';
        $config->envelopeFrom = '';
    }

    protected function capturingMailer(): AppMailer
    {
        return new AppMailer(function (EmailConfig $c): AppEmail {
            $this->emailFactoryCalls++;
            $email           = new CapturingEmail($c);
            $email->result   = $this->nextEmailResult;
            $this->lastEmail = $email;

            return $email;
        });
    }

    protected function mailer(?DocumentStore $store = null): InvoiceMailer
    {
        return new InvoiceMailer($this->rw(), $this->capturingMailer(), null, $store);
    }

    /**
     * Vystavena faktura (schema 2, s vykazom) cez realny InvoiceIssuer.
     *
     * @param array<string, mixed> $client prepisy klienta
     *
     * @return array{id: int, entity: int, client: int}
     */
    protected function issuedInvoice(array $client = []): array
    {
        $s   = $this->issueSetup($client === [] ? [] : ['client' => $client]);
        $cmd = $this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']);

        return ['id' => $this->issuer()->issue($cmd)->id, 'entity' => $s['entity'], 'client' => $s['client']];
    }

    /**
     * @param list<string>        $to
     * @param list<string>        $cc
     * @param array<string,mixed> $o
     */
    protected function sendCommand(int $invoiceId, array $to = ['fakturacia@all4net.example'], array $cc = [], array $o = []): SendCommand
    {
        return new SendCommand(
            $invoiceId,
            (string) ($o['kind'] ?? 'invoice'),
            $to,
            $cc,
            (string) ($o['subject'] ?? 'Faktura 202610001'),
            (string) ($o['body'] ?? "Dobry den,\n\nv prilohe faktura."),
            (string) ($o['requestKey'] ?? $this->uuid4()),
            $o['userId'] ?? null,
        );
    }

    protected function sendCount(): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM invoice_sends')->getRow()->c;
    }
}
