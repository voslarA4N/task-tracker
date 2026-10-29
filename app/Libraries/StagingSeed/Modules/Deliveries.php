<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed\Modules;

use App\Libraries\Delivery\InvoiceMailer;
use App\Libraries\Delivery\ManualSendService;
use App\Libraries\Delivery\SendCommand;
use App\Libraries\Delivery\SendRejected;
use App\Libraries\Platform\Uuid;
use App\Libraries\Receivables\PaymentRejected;
use App\Libraries\Receivables\PaymentService;
use App\Libraries\StagingSeed\SeedContext;
use App\Libraries\StagingSeed\SeedModule;
use RuntimeException;

/**
 * Historia dorucenia a uhrad (D-25) cez skutocne sluzby (InvoiceMailer, ManualSendService, PaymentService) v case udalosti.
 * Pocas modulu je email.transport = log, takze sa nikdy nic neodosle (D-02); povodna hodnota sa vo finally vrati.
 */
final class Deliveries implements SeedModule
{
    public function name(): string
    {
        return 'deliveries';
    }

    public function run(SeedContext $ctx): void
    {
        $config   = config('Email');
        $original = $config->transport;
        $domains  = $config->allowedFromDomains;
        $at       = strrpos((string) $config->fromEmail, '@');

        $config->transport = 'log';

        // nic sa neodosiela, takze odosielatel z dev/test konfiguracie (inej domeny) seed nezablokuje; hodnota sa vo finally vrati
        if ($at !== false) {
            $config->allowedFromDomains = $domains . ',' . substr((string) $config->fromEmail, $at + 1);
        }

        try {
            $this->apply($ctx);
        } finally {
            $config->transport          = $original;
            $config->allowedFromDomains = $domains;
        }
    }

    private function apply(SeedContext $ctx): void
    {
        $mailer  = new InvoiceMailer($ctx->db);
        $manual  = new ManualSendService($ctx->db);
        $payment = new PaymentService($ctx->db);
        $userId  = $ctx->id('user', 'admin');
        $counts  = [];

        foreach ($ctx->plan->deliveries as $e) {
            $ctx->clock->at($e['at']);

            $invoiceId = $ctx->id('invoice', $e['invoice']);
            $date      = substr($e['at'], 0, 10);

            try {
                switch ($e['type']) {
                    case 'send':
                    case 'reminder':
                        $kind  = $e['type'] === 'send' ? 'invoice' : 'reminder';
                        $draft = $mailer->draft($invoiceId, $kind);
                        $mailer->send(new SendCommand($invoiceId, $kind, $draft['to'], $draft['cc'], $draft['subject'], $draft['body'], Uuid::v4(), $userId));
                        break;

                    case 'manual':
                        $manual->markSent($invoiceId, $date, $e['note'] ?? null, Uuid::v4(), $userId);
                        break;

                    case 'payment':
                        $amount = $e['amount'] ?? null;

                        if (($e['partial'] ?? false) === true) {
                            $amount = $this->half($ctx, $invoiceId);
                        }

                        $ctx->put('payment', (string) $e['ref'], $payment->record($invoiceId, $amount, $date, null, Uuid::v4(), $userId)->id);
                        break;

                    case 'payment_void':
                        $payment->void($ctx->id('payment', (string) $e['ref']), (string) $e['reason'], $userId);
                        break;

                    default:
                        throw new RuntimeException('Unknown seed delivery type ' . $e['type']);
                }
            } catch (PaymentRejected|SendRejected $ex) {
                throw new RuntimeException('Seed delivery ' . $e['type'] . ' ' . $e['invoice'] . ' rejected: ' . $ex->reason, 0, $ex);
            }

            $counts[$e['type']] = ($counts[$e['type']] ?? 0) + 1;
        }

        $ctx->notes['deliveries'] = $counts;
    }

    /**
     * Polovica sumy faktury (bcmath, 2 desatinne miesta), zostatok ostane.
     */
    private function half(SeedContext $ctx, int $invoiceId): string
    {
        $total = (string) $ctx->db->query('SELECT total FROM invoices WHERE id = :i:', ['i' => $invoiceId])->getRow()->total;

        return bcdiv($total, '2', 2);
    }
}
