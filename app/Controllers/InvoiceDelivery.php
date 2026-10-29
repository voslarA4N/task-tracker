<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Delivery\InvoiceMailer;
use App\Libraries\Delivery\ManualSendService;
use App\Libraries\Delivery\SendCommand;
use App\Libraries\Delivery\SendRejected;
use App\Libraries\Master\CcEmails;
use App\Libraries\Platform\Money;
use App\Libraries\Platform\Uuid;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

/**
 * Odoslanie vystavenej faktury a vykazu klientovi e-mailom (MAIL-01, D-05).
 */
final class InvoiceDelivery extends BaseController
{
    private const NOT_FOUND_REASONS = ['not_found', 'external', 'not_sendable'];

    public function sendForm(int $id): ResponseInterface
    {
        return $this->form($id, 'invoice');
    }

    public function resendForm(int $id): ResponseInterface
    {
        return $this->form($id, 'resend');
    }

    /**
     * Rucna upomienka (D-24): iba faktura, iba pri zostatku > 0.
     */
    public function remindForm(int $id): ResponseInterface
    {
        return $this->form($id, 'reminder');
    }

    public function remind(int $id): ResponseInterface
    {
        return $this->submit($id, 'reminder');
    }

    public function send(int $id): ResponseInterface
    {
        return $this->submit($id, 'invoice');
    }

    public function resend(int $id): ResponseInterface
    {
        return $this->submit($id, 'resend');
    }

    /**
     * Rucne oznacenie ako odoslanej (D-29).
     */
    public function markSentForm(int $id): ResponseInterface
    {
        $invoice = $this->markable($id);

        return $this->markPanel('invoices/_mark_sent_sheet', 200, $invoice, [
            'date'        => service('clock')->today(),
            'note'        => '',
            'request_key' => Uuid::v4(),
        ], [], null);
    }

    public function markSent(int $id): ResponseInterface
    {
        // Opakovany POST po uspechu vrati povodny vysledok, nie 404 zo stavovej brany (WR-02)
        if ((new ManualSendService())->replayFor($id, $this->post('request_key')) !== null) {
            return $this->done(lang('Deliveries.markedSent'), '/invoices/' . $id);
        }

        $invoice = $this->markable($id);
        $values  = [
            'date'        => $this->post('date'),
            'note'        => $this->post('note'),
            'request_key' => $this->post('request_key'),
        ];

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $values['request_key']) !== 1) {
            return $this->markPanel('invoices/_mark_sent_form', 422, $invoice, array_merge($values, ['request_key' => Uuid::v4()]), [], lang('Deliveries.errorRequest'));
        }

        try {
            (new ManualSendService())->markSent($id, $values['date'], $values['note'], $values['request_key'], auth()->id() === null ? null : (int) auth()->id());
        } catch (SendRejected $e) {
            if (in_array($e->reason, self::NOT_FOUND_REASONS, true)) {
                throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
            }

            return match ($e->reason) {
                'future_date'   => $this->markPanel('invoices/_mark_sent_form', 422, $invoice, $values, ['date' => lang('Deliveries.errorSentFuture')], null),
                'before_issue'  => $this->markPanel('invoices/_mark_sent_form', 422, $invoice, $values, ['date' => lang('Deliveries.errorSentBeforeIssue')], null),
                'note_too_long' => $this->markPanel('invoices/_mark_sent_form', 422, $invoice, $values, ['note' => lang('Deliveries.errorMarkNote')], null),
                'already_sent'  => $this->markPanel('invoices/_mark_sent_form', 409, $invoice, $values, [], lang('Deliveries.errorAlreadySent')),
                default         => $this->markPanel('invoices/_mark_sent_form', 422, $invoice, $values, ['date' => lang('Deliveries.errorSentDate')], null),
            };
        }

        return $this->done(lang('Deliveries.markedSent'), '/invoices/' . $id);
    }

    public function voidSendForm(int $sendId): ResponseInterface
    {
        return $this->voidPanel(200, $this->manualSend($sendId), '', null, null, true);
    }

    public function voidSend(int $sendId): ResponseInterface
    {
        $send   = $this->manualSend($sendId);
        $reason = $this->post('reason');

        try {
            $invoiceId = (new ManualSendService())->voidManual($sendId, $reason, auth()->id() === null ? null : (int) auth()->id());
        } catch (SendRejected $e) {
            return match ($e->reason) {
                'not_found', 'not_manual' => throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound')),
                'already_voided'          => $this->voidPanel(409, $send, $reason, null, lang('Deliveries.errorVoidAlready')),
                'note_too_long'           => $this->voidPanel(422, $send, $reason, lang('Deliveries.errorVoidReasonLong')),
                default                   => $this->voidPanel(422, $send, $reason, lang('Deliveries.errorVoidReason')),
            };
        }

        return $this->done(lang('Deliveries.voidMarkDone'), '/invoices/' . $invoiceId);
    }

    /**
     * @return array<string, mixed>
     */
    private function markable(int $id): array
    {
        $row = db_connect()->query(
            'SELECT i.id, i.number, i.origin, i.type, i.issue_date, v.status, v.sent_at FROM invoices i JOIN invoice_status_v v ON v.id = i.id WHERE i.id = :id:',
            ['id' => $id],
        )->getRowArray();

        // stav dorucenia podla sent_at (zaplatena, ale neodoslana faktura sa da oznacit, WR-01)
        if ($row === null || $row['origin'] !== 'issued' || $row['type'] !== 'invoice' || $row['sent_at'] !== null) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        return $row;
    }

    /**
     * @return array{id: int, invoice_id: int, sent_at: string}
     */
    private function manualSend(int $sendId): array
    {
        $row = db_connect()->query('SELECT id, invoice_id, kind, sent_at FROM invoice_sends WHERE id = :id:', ['id' => $sendId])->getRowArray();

        if ($row === null || $row['kind'] !== 'manual') {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        return ['id' => (int) $row['id'], 'invoice_id' => (int) $row['invoice_id'], 'sent_at' => (string) $row['sent_at']];
    }

    /**
     * @param array<string, mixed>  $invoice
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function markPanel(string $fragment, int $status, array $invoice, array $values, array $errors, ?string $alert = null): ResponseInterface
    {
        $body = htmx_view($fragment, [
            'invoice' => $invoice,
            'today'   => service('clock')->today(),
            'values'  => $values,
            'errors'  => $errors,
            'alert'   => $alert,
            'backUrl' => '/invoices/' . (int) $invoice['id'],
        ], 'invoices/mark_sent_page');

        return $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    /**
     * @param array{id: int, invoice_id: int, sent_at: string} $send
     */
    private function voidPanel(int $status, array $send, string $reason, ?string $error, ?string $alert = null, bool $sheet = false): ResponseInterface
    {
        $fmt  = new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'Europe/Bratislava');
        $data = [
            'send'    => $send,
            'sentOn'  => (string) $fmt->format(service('clock')->now()->modify($send['sent_at'])),
            'reason'  => $reason,
            'error'   => $error,
            'alert'   => $alert,
            'void'    => true,
            'backUrl' => '/invoices/' . $send['invoice_id'],
            'invoice' => ['id' => $send['invoice_id']],
        ];
        $body = $sheet && is_htmx_fragment_request() ? view('invoices/_send_void_sheet', $data) : htmx_view('invoices/_send_void_form', $data, 'invoices/mark_sent_page');

        return $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    private function done(string $message, string $to, bool $flash = true): ResponseInterface
    {
        if ($flash) {
            session()->setFlashdata('app_notice', ['type' => 'success', 'message' => $message]);
        }

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', $to)->setHeader('HX-Trigger', 'allocationChanged')->setBody('');
        }

        return redirect()->to($to);
    }

    private function form(int $id, string $kind): ResponseInterface
    {
        $mailer = new InvoiceMailer();

        try {
            $draft = $mailer->draft($id, $kind);
        } catch (SendRejected) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        if ($kind === 'reminder' && Money::of((string) ($draft['invoice']['balance'] ?? '0'))->compare(Money::zero()) <= 0) {
            return $this->noBalance($draft, $kind);
        }

        return $this->panel('invoices/_delivery_sheet', 200, $draft, [
            'to'          => implode(', ', $draft['to']),
            'cc'          => implode(', ', $draft['cc']),
            'subject'     => $draft['subject'],
            'body'        => $draft['body'],
            'request_key' => Uuid::v4(),
            'return_to'   => $this->returnTo($this->request->getGet('return_to'), $id),
        ], [], null, $kind);
    }

    private function submit(int $id, string $kind): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $mailer = new InvoiceMailer();

        try {
            $draft = $mailer->draft($id, $kind);
        } catch (SendRejected) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        $values = [
            'to'          => $this->post('to'),
            'cc'          => $this->post('cc'),
            'subject'     => $this->post('subject'),
            'body'        => $this->post('body'),
            'request_key' => $this->post('request_key'),
            'return_to'   => $this->returnTo($this->post('return_to'), $id),
        ];

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $values['request_key']) !== 1) {
            return $this->panel('invoices/_delivery_form', 422, $draft, array_merge($values, ['request_key' => Uuid::v4()]), [], lang('Deliveries.errorRequest'), $kind);
        }

        $command = new SendCommand(
            invoiceId: $id,
            kind: $kind,
            to: CcEmails::parse($values['to']),
            cc: CcEmails::parse($values['cc']),
            subject: trim($values['subject']),
            body: str_replace("\r\n", "\n", $values['body']),
            requestKey: $values['request_key'],
            userId: auth()->id() === null ? null : (int) auth()->id(),
        );

        try {
            $result = $mailer->send($command);
        } catch (SendRejected $e) {
            if (in_array($e->reason, self::NOT_FOUND_REASONS, true)) {
                throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
            }

            if ($e->reason === 'no_balance') {
                return $this->noBalance($draft, $kind);
            }

            return $this->rejected($e, $draft, $values, $kind);
        }

        $message = $result->transport === 'log'
            ? lang('Deliveries.sentLogged')
            : lang('Deliveries.sent', [implode(', ', array_merge($result->to, $result->cc))]);
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => $message]);

        return $this->done($message, $values['return_to'], false);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function noBalance(array $draft, string $kind): ResponseInterface
    {
        return $this->panel('invoices/_delivery_form', 409, $draft, [
            'to'          => '',
            'cc'          => '',
            'subject'     => (string) $draft['subject'],
            'body'        => (string) $draft['body'],
            'request_key' => Uuid::v4(),
            'return_to'   => '/invoices/' . (int) $draft['invoice']['id'],
        ], [], lang('Deliveries.errorNoBalance'), $kind);
    }

    private function returnTo(mixed $raw, int $id): string
    {
        $detail = '/invoices/' . $id;

        return is_string($raw) && ($raw === '/' || $raw === $detail) ? $raw : $detail;
    }

    /**
     * @param array<string, mixed>  $draft
     * @param array<string, string> $values
     */
    private function rejected(SendRejected $e, array $draft, array $values, string $kind): ResponseInterface
    {
        $errors = [];
        $alert  = null;
        $status = 422;

        switch ($e->reason) {
            case 'no_recipients':
                $errors['to'] = lang('Deliveries.errorNoRecipient');
                break;

            case 'too_many_recipients':
                $errors['to'] = lang('Deliveries.errorTooMany');
                break;

            case 'invalid_address':
                $errors[$e->field === 'cc' ? 'cc' : 'to'] = lang('Deliveries.errorAddress');
                break;

            case 'empty_content':
                $errors['subject'] = lang('Deliveries.errorContent');
                break;

            case 'invalid_subject':
                $errors['subject'] = lang('Deliveries.errorSubjectChars');
                break;

            case 'too_long':
                $errors['body'] = lang('Deliveries.errorTooLong');
                break;

            case 'sender_invalid':
                $alert = lang('Deliveries.errorSender');
                break;

            case 'attachments_failed':
                $status = 502;
                $alert  = lang('Deliveries.errorAttachments');
                break;

            case 'record_failed':
                $status = 502;
                $alert  = lang('Deliveries.errorRecord');
                break;

            default: // smtp_failed
                $status = 502;
                $alert  = lang('Deliveries.errorSmtp');
        }

        // po record_failed e-mail uz odisiel: ziadne „Skusit znova“; rovnaky request_key ho ani neposle znova (WR-03)
        return $this->panel('invoices/_delivery_form', $status, $draft, $values, $errors, $alert, $kind, $status === 502 && $e->reason !== 'record_failed');
    }

    /**
     * @param array<string, mixed>  $draft
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function panel(string $fragment, int $status, array $draft, array $values, array $errors, ?string $alert, string $kind, ?bool $retry = null): ResponseInterface
    {
        $invoice = (array) $draft['invoice'];
        $client  = db_connect()->query('SELECT name FROM clients WHERE id = :id:', ['id' => (int) $invoice['client_id']])->getRowArray();
        $body    = htmx_view($fragment, [
            'invoice'    => $invoice,
            'clientName' => (string) ($client['name'] ?? ''),
            'draft'      => $draft,
            'values'     => $values,
            'errors'     => $errors,
            'alert'      => $alert,
            'kind'       => $kind,
            'backUrl'    => '/invoices/' . (int) $invoice['id'],
            'retry'      => $retry ?? $status === 502,
        ], 'invoices/delivery_page');

        return $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    private function post(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
    }
}
