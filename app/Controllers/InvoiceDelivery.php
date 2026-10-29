<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Delivery\InvoiceMailer;
use App\Libraries\Delivery\SendCommand;
use App\Libraries\Delivery\SendRejected;
use App\Libraries\Master\CcEmails;
use App\Libraries\Platform\Uuid;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Odoslanie vystavenej faktury a vykazu klientovi e-mailom (MAIL-01, D-05).
 */
final class InvoiceDelivery extends BaseController
{
    private const NOT_FOUND_REASONS = ['not_found', 'external', 'not_sendable'];

    public function sendForm(int $id): ResponseInterface
    {
        $mailer = new InvoiceMailer();

        try {
            $draft = $mailer->draft($id, 'invoice');
        } catch (SendRejected) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        return $this->panel('invoices/_delivery_sheet', 200, $draft, [
            'to'          => implode(', ', $draft['to']),
            'cc'          => implode(', ', $draft['cc']),
            'subject'     => $draft['subject'],
            'body'        => $draft['body'],
            'request_key' => Uuid::v4(),
        ], [], null);
    }

    public function send(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $mailer = new InvoiceMailer();

        try {
            $draft = $mailer->draft($id, 'invoice');
        } catch (SendRejected) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        $values = [
            'to'          => $this->post('to'),
            'cc'          => $this->post('cc'),
            'subject'     => $this->post('subject'),
            'body'        => $this->post('body'),
            'request_key' => $this->post('request_key'),
        ];

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $values['request_key']) !== 1) {
            return $this->panel('invoices/_delivery_form', 422, $draft, array_merge($values, ['request_key' => Uuid::v4()]), [], lang('Deliveries.errorRequest'));
        }

        $command = new SendCommand(
            invoiceId: $id,
            kind: 'invoice',
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

            return $this->rejected($e, $draft, $values);
        }

        $message = $result->transport === 'log'
            ? lang('Deliveries.sentLogged')
            : lang('Deliveries.sent', [implode(', ', array_merge($result->to, $result->cc))]);
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => $message]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/invoices/' . $id)->setHeader('HX-Trigger', 'allocationChanged')->setBody('');
        }

        return redirect()->to('/invoices/' . $id);
    }

    /**
     * @param array<string, mixed>  $draft
     * @param array<string, string> $values
     */
    private function rejected(SendRejected $e, array $draft, array $values): ResponseInterface
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

        return $this->panel('invoices/_delivery_form', $status, $draft, $values, $errors, $alert);
    }

    /**
     * @param array<string, mixed>  $draft
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function panel(string $fragment, int $status, array $draft, array $values, array $errors, ?string $alert): ResponseInterface
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
            'backUrl'    => '/invoices/' . (int) $invoice['id'],
            'retry'      => $status === 502,
        ], 'invoices/delivery_page');

        return $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    private function post(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
    }
}
