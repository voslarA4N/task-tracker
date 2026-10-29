<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Platform\Uuid;
use App\Libraries\Receivables\InvoiceStatusQueries;
use App\Libraries\Receivables\PaymentRejected;
use App\Libraries\Receivables\PaymentService;
use App\Libraries\Receivables\RecordedPayment;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

/**
 * Zapis a storno uhrad na detaile faktury (PAY-01, PAY-03, D-18, D-19, D-28).
 */
final class Payments extends BaseController
{
    public function form(int $id): ResponseInterface
    {
        $ctx = $this->context($id);

        return $this->panel('invoices/_payment_sheet', 200, $ctx, [
            'paid_on'     => service('clock')->today(),
            'amount'      => '',
            'note'        => '',
            'request_key' => Uuid::v4(),
            'return_to'   => $this->returnTo($this->request->getGet('return_to'), $id),
        ], []);
    }

    public function record(int $id): ResponseInterface
    {
        // Opakovany POST (strata odpovede) vrati povodny vysledok skor, nez stavova brana odmietne zaplatenu fakturu (WR-02)
        $replay = (new PaymentService())->replayFor($id, $this->post('request_key'));

        if ($replay !== null) {
            return $this->done($this->recordedMessage($replay, $this->currency($id)), $this->returnTo($this->post('return_to'), $id));
        }

        $ctx    = $this->context($id);
        $values = [
            'paid_on'     => $this->post('paid_on'),
            'amount'      => $this->post('amount'),
            'note'        => $this->post('note'),
            'request_key' => $this->post('request_key'),
            'return_to'   => $this->returnTo($this->post('return_to'), $id),
        ];

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $values['request_key']) !== 1) {
            return $this->panel('invoices/_payment_form', 422, $ctx, array_merge($values, ['request_key' => Uuid::v4()]), [], lang('Payments.errorRequest'));
        }

        try {
            $result = (new PaymentService())->record(
                $id,
                trim($values['amount']) === '' ? null : $values['amount'],
                $values['paid_on'],
                $values['note'],
                $values['request_key'],
                auth()->id() === null ? null : (int) auth()->id(),
            );
        } catch (PaymentRejected $e) {
            if (in_array($e->reason, ['not_found', 'not_payable'], true)) {
                throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
            }

            return $this->rejected($e, $ctx, $values);
        }

        return $this->done($this->recordedMessage($result, $ctx['currency']), $values['return_to']);
    }

    private function recordedMessage(RecordedPayment $result, string $currency): string
    {
        return $result->statusAfter === 'paid'
            ? lang('Payments.recordedPaid')
            : lang('Payments.recorded', [MoneyFormat::format($result->amount, $currency)]);
    }

    public function impact(int $id): ResponseInterface
    {
        $this->context($id);

        $raw    = $this->post('amount');
        $impact = (new PaymentService())->impact($id, trim($raw) === '' ? null : $raw);

        return $this->response->setHeader('Vary', 'HX-Request')->setBody(view('invoices/_payment_impact', [
            'balanceAfter' => MoneyFormat::format($impact['balance_after'], $this->currency($id)),
            'statusAfter'  => $impact['status_after'],
        ]));
    }

    public function voidForm(int $paymentId): ResponseInterface
    {
        return $this->voidPanel(200, $this->payment($paymentId), '', null, null, true);
    }

    public function void(int $paymentId): ResponseInterface
    {
        $payment = $this->payment($paymentId);
        $reason  = $this->post('reason');

        try {
            $invoiceId = (new PaymentService())->void($paymentId, $reason, auth()->id() === null ? null : (int) auth()->id());
        } catch (PaymentRejected $e) {
            return match ($e->reason) {
                'not_found'      => throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound')),
                'already_voided' => $this->voidPanel(409, $payment, $reason, null, lang('Payments.errorVoided')),
                'note_too_long'  => $this->voidPanel(422, $payment, $reason, lang('Payments.errorReasonLong')),
                default          => $this->voidPanel(422, $payment, $reason, lang('Payments.errorReason')),
            };
        }

        return $this->done(lang('Payments.voided'), '/invoices/' . $invoiceId);
    }

    /**
     * @return array{invoice: array<string, mixed>, status: array<string, mixed>, currency: string, clientName: string}
     */
    private function context(int $id): array
    {
        $invoice = db_connect()->query(
            'SELECT i.id, i.number, i.origin, i.type, c.name AS client_name FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = :id:',
            ['id' => $id],
        )->getRowArray();
        $status = (new InvoiceStatusQueries())->forInvoice($id, service('clock')->today());

        if ($invoice === null || $status === null || $invoice['origin'] !== 'issued' || $invoice['type'] !== 'invoice'
                              || ! in_array($status['status'], ['issued', 'sent', 'partially_paid'], true)) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        return ['invoice' => $invoice, 'status' => $status, 'currency' => $status['currency'], 'clientName' => (string) $invoice['client_name']];
    }

    private function currency(int $id): string
    {
        $row = db_connect()->query('SELECT currency FROM invoices WHERE id = :id:', ['id' => $id])->getRowArray();

        return trim((string) ($row['currency'] ?? 'EUR'));
    }

    /**
     * @return array{id: int, invoice_id: int, amount: string, paid_on: string, currency: string, number: string}
     */
    private function payment(int $paymentId): array
    {
        $row = db_connect()->query(
            'SELECT p.id, p.invoice_id, p.amount, p.paid_on, i.currency, i.number FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.id = :id:',
            ['id' => $paymentId],
        )->getRowArray();

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        return [
            'id'         => (int) $row['id'],
            'invoice_id' => (int) $row['invoice_id'],
            'amount'     => (string) $row['amount'],
            'paid_on'    => (string) $row['paid_on'],
            'currency'   => trim((string) $row['currency']),
            'number'     => (string) ($row['number'] ?? ''),
        ];
    }

    /**
     * @param array{invoice: array<string, mixed>, status: array<string, mixed>, currency: string, clientName: string} $ctx
     * @param array<string, string>                                                                                    $values
     */
    private function rejected(PaymentRejected $e, array $ctx, array $values): ResponseInterface
    {
        $errors = [];
        $alert  = null;
        $status = 422;
        $fmt    = static fn (?string $v): string => MoneyFormat::format($v ?? '0.00', $ctx['currency']);

        switch ($e->reason) {
            case 'overpaid':
                $errors['amount'] = lang('Payments.errorOverpaid', [$fmt($e->balance)]);
                break;

            case 'non_positive':
                $errors['amount'] = lang('Payments.errorNonPositive');
                break;

            case 'future_date':
                $errors['paid_on'] = lang('Payments.errorFuture');
                break;

            case 'invalid_date':
                $errors['paid_on'] = lang('Payments.errorDate');
                break;

            case 'note_too_long':
                $errors['note'] = lang('Payments.errorNote');
                break;

            case 'already_paid':
                $alert = lang('Payments.errorPaid');
                break;

            default: // invalid_amount
                $errors['amount'] = lang('Payments.errorAmount');
        }

        return $this->panel('invoices/_payment_form', $status, $ctx, $values, $errors, $alert);
    }

    /**
     * @param array{invoice: array<string, mixed>, status: array<string, mixed>, currency: string, clientName: string} $ctx
     * @param array<string, string>                                                                                    $values
     * @param array<string, string>                                                                                    $errors
     */
    private function panel(string $fragment, int $status, array $ctx, array $values, array $errors, ?string $alert = null): ResponseInterface
    {
        $invoice = $ctx['invoice'];
        $body    = htmx_view($fragment, [
            'invoice'    => $invoice,
            'clientName' => $ctx['clientName'],
            'balance'    => MoneyFormat::format((string) $ctx['status']['balance'], $ctx['currency']),
            'today'      => service('clock')->today(),
            'values'     => $values,
            'errors'     => $errors,
            'alert'      => $alert,
            'backUrl'    => '/invoices/' . (int) $invoice['id'],
        ], 'invoices/payment_page');

        return $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    /**
     * @param array{id: int, invoice_id: int, amount: string, paid_on: string, currency: string, number: string} $payment
     */
    private function voidPanel(int $status, array $payment, string $reason, ?string $error, ?string $alert = null, bool $sheet = false): ResponseInterface
    {
        $fmt  = new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'Europe/Bratislava');
        $when = service('clock')->parseDate($payment['paid_on']);
        $data = [
            'payment'    => $payment,
            'amount'     => MoneyFormat::format($payment['amount'], $payment['currency']),
            'paidOn'     => $when === null ? $payment['paid_on'] : (string) $fmt->format($when),
            'reason'     => $reason,
            'error'      => $error,
            'alert'      => $alert,
            'void'       => true,
            'backUrl'    => '/invoices/' . $payment['invoice_id'],
            'invoice'    => ['id' => $payment['invoice_id'], 'number' => $payment['number']],
            'clientName' => '',
        ];
        $body = $sheet && is_htmx_fragment_request() ? view('invoices/_payment_void_sheet', $data) : htmx_view('invoices/_payment_void_form', $data, 'invoices/payment_page');

        return $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    private function done(string $message, string $to): ResponseInterface
    {
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => $message]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', $to)->setHeader('HX-Trigger', 'allocationChanged')->setBody('');
        }

        return redirect()->to($to);
    }

    private function returnTo(mixed $raw, int $id): string
    {
        $detail = '/invoices/' . $id;

        if (is_string($raw) && ($raw === '/' || $raw === $detail)) {
            return $raw;
        }

        return $detail;
    }

    private function post(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
    }
}
