<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Invoicing\Draft;
use App\Libraries\Invoicing\InvoiceDraftService;
use App\Libraries\Invoicing\IssueCommand;
use App\Libraries\Invoicing\IssueInput;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\PreviewInput;
use App\Libraries\Master\ClientService;
use App\Libraries\Work\AllocationLocked;
use App\Libraries\Work\AllocationService;
use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\LastClient;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;
use InvalidArgumentException;
use Throwable;

/**
 * Fakturacia mesiaca: vyber, nahlad (iba citanie) a presun casti z nahladu. Vystavenie z nahladu (issue).
 */
final class Invoicing extends BaseController
{
    public function new(): ResponseInterface
    {
        return $this->render(false);
    }

    public function preview(): ResponseInterface
    {
        return $this->render(true);
    }

    /**
     * Spodny panel presunu nezamknutej casti z nahladu do ineho mesiaca (D-03).
     */
    public function moveSheet(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        [$allocation, $title] = $this->loadPart($id, PreviewInput::fromGet($request));

        return $this->movePanel($allocation['invoice_id'] === null ? 200 : 409, $allocation, $title, PreviewInput::fromGet($request), 'next', '', [], $allocation['invoice_id'] !== null);
    }

    public function move(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $selection            = PreviewInput::fromPost($request);
        [$allocation, $title] = $this->loadPart($id, $selection);

        $choice = $request->getPost('month_choice');
        $choice = $choice === 'other' ? 'other' : 'next';
        $other  = $request->getPost('billing_month_other');
        $other  = is_string($other) ? trim($other) : '';
        $target = $choice === 'other' ? $other : BillingMonth::next((string) $allocation['billing_month']);

        if (BillingMonth::parse($target) === null || ! BillingMonth::inRange($target)) {
            $bounds = BillingMonth::rangeBounds();
            $locale = $request->getLocale();
            $errors = ['billing_month' => lang('Invoicing.errorMonth', [BillingMonth::label($bounds['min'], $locale), BillingMonth::label($bounds['max'], $locale)])];

            return $this->movePanel(422, $allocation, $title, $selection, $choice, $other, $errors, false);
        }

        try {
            (new AllocationService())->moveMonth($id, $target);
        } catch (AllocationLocked) {
            return $this->movePanel(409, $allocation, $title, $selection, $choice, $other, [], true);
        } catch (InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound(lang('Allocations.notFound'));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Invoicing.moveDone', [mb_strtolower(BillingMonth::label($target, $request->getLocale()))])]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Trigger', 'allocationChanged')->setBody('');
        }

        return redirect()->to('/invoices/new?' . http_build_query(array_filter($selection, static fn (string $v): bool => $v !== '')));
    }

    /**
     * Vystavenie faktury z nahladu (D-06). Suma ani cislo sa z formulara neprijimaju, vsetko pocita server.
     */
    public function issue(): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $input  = IssueInput::fromPost($request);
        $errors = IssueInput::validate($input);
        $raw    = [
            'client'        => $input['client_id'],
            'month'         => $input['billing_month'],
            'entity'        => $input['entity_id'],
            'issue_date'    => $input['issue_date'],
            'delivery_date' => $input['delivery_date'],
            'due_date'      => $input['due_date'],
        ];
        $raw += [
            'payment_method'     => in_array($input['payment_method'], IssueCommand::PAYMENT_METHODS, true) ? $input['payment_method'] : 'transfer',
            'payment_note'       => $input['payment_note'],
            'payment_note_dirty' => '1',
        ];

        if ($errors !== []) {
            $payErrors = array_intersect_key($errors, ['payment_method' => true, 'payment_note' => true]);

            return $this->renderPreview(false, $raw, 422, array_values(array_map(static fn (string $m): array => ['message' => $m], array_diff_key($errors, $payErrors))), $payErrors);
        }

        try {
            $issued = service('invoiceIssuer')->issue(IssueInput::toCommand($input, auth()->id() === null ? null : (int) auth()->id()));
        } catch (IssueRejected $e) {
            if ($e->reason === 'already_issued' && $e->invoiceId !== null) {
                return $this->issuedRedirect($e->invoiceId, $e->invoiceNumber);
            }

            return $this->rejected($e, $raw);
        } catch (Throwable $e) {
            log_message('error', 'Invoice issue failed: {class}: {message}', ['class' => $e::class, 'message' => $e->getMessage()]);

            return $this->renderPreview(false, $raw, 500, [['message' => lang('Invoicing.errorFailed')]]);
        }

        return $this->issuedRedirect($issued->id, $issued->number);
    }

    private function issuedRedirect(int $invoiceId, ?string $number): ResponseInterface
    {
        if ($number !== null) {
            session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Invoicing.issued', [$number])]);
        }

        $url = '/invoices/' . $invoiceId;

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', $url)->setBody('');
        }

        return redirect()->to($url);
    }

    /**
     * @param array<string, string> $raw
     */
    private function rejected(IssueRejected $e, array $raw): ResponseInterface
    {
        switch ($e->reason) {
            case 'parts_changed':
                if ($e->invoiceNumber !== null && $e->invoiceId !== null) {
                    $alert = [
                        'message' => lang('Invoicing.errorDuplicate', [$e->invoiceNumber]),
                        'link'    => ['href' => '/invoices/' . $e->invoiceId, 'label' => lang('Invoicing.showInvoice', [$e->invoiceNumber])],
                    ];

                    return $this->renderPreview(false, $raw, 409, [$alert]);
                }

                return $this->renderPreview(false, $raw, 409, [['message' => lang('Invoicing.errorStale')]]);

            case 'preview_stale':
                return $this->renderPreview(false, $raw, 409, [['message' => lang('Invoicing.errorStale')]]);

            case 'backdate_unconfirmed':
                return $this->renderPreview(false, $raw, 422, [['message' => lang('Invoicing.errorBackdate')]]);

            case 'sequence_overflow':
                $format = is_scalar($e->context['format'] ?? null) ? (string) $e->context['format'] : '';

                return $this->renderPreview(false, $raw, 422, [['message' => lang('Invoicing.errorSequence', [$format])]]);

            case 'qr_failed':
                return $this->renderPreview(false, $raw, 500, [['message' => lang('Invoicing.errorQrFailed')]]);

            case 'report_failed':
                return $this->renderPreview(false, $raw, 500, [['message' => lang('Invoicing.errorReportFailed')]]);

            default:
                return $this->renderPreview(false, $raw, 422, [['message' => self::errorMessage($e->reason, $e->context)]]);
        }
    }

    /**
     * Mapovanie dovodu odmietnutia na text (D-10, D-18). Pre neznamy dovod vseobecna sprava.
     *
     * @param array<string, mixed> $context
     */
    public static function errorMessage(string $reason, array $context = []): string
    {
        $text = static fn (string $k): string => is_scalar($context[$k] ?? null) ? (string) $context[$k] : '';

        return match ($reason) {
            'mixed_currency' => lang('Invoicing.errorMixedCurrency', [
                is_array($context['currencies'] ?? null) ? implode(', ', array_map('strval', $context['currencies'])) : '',
            ]),
            'currency_mismatch'  => lang('Invoicing.errorCurrencyMismatch', [$text('currency'), $text('entity_currency')]),
            'legal_text_missing' => lang('Invoicing.errorLegalText', [self::languageName($text('language'))]),
            'no_bank_account'    => lang('Invoicing.errorNoBank', [$text('currency')]),
            'vat_code_missing'   => lang('Invoicing.errorVatCode'),
            'version_not_found'  => lang('Invoicing.errorVersion'),
            'amount_mismatch'    => lang('Invoicing.errorAmountMismatch'),
            'entity_inactive'    => lang('Invoicing.errorEntityInactive', [self::dateLabel($text('date'))]),
            default              => lang('Invoicing.errorGeneric'),
        };
    }

    public static function dateLabel(string $iso): string
    {
        if ($iso === '' || service('clock')->parseDate($iso) === null) {
            return $iso;
        }

        $fmt = new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Bratislava');

        return (string) $fmt->format(service('clock')->now()->modify($iso . ' 12:00:00'));
    }

    /**
     * @param array<string, string> $selection
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function loadPart(int $id, array $selection): array
    {
        $allocation = (new AllocationService())->find($id);
        $task       = $allocation === null ? null : db_connect()->query('SELECT title, client_id FROM tasks WHERE id = :id:', ['id' => $allocation['task_id']])->getRowArray();

        if ($allocation === null || $task === null || ($selection['client'] !== '' && preg_match('/^\d{1,18}$/D', $selection['client']) === 1 && (int) $selection['client'] !== (int) $task['client_id'])) {
            throw PageNotFoundException::forPageNotFound(lang('Allocations.notFound'));
        }

        return [$allocation, (string) $task['title']];
    }

    /**
     * @param array<string, mixed>  $allocation
     * @param array<string, string> $selection
     * @param array<string, string> $errors
     */
    private function movePanel(int $status, array $allocation, string $title, array $selection, string $choice, string $other, array $errors, bool $locked): ResponseInterface
    {
        $locale = service('request')->getLocale();
        $month  = (string) $allocation['billing_month'];
        $bounds = BillingMonth::rangeBounds();
        $data   = [
            'allocation' => $allocation,
            'taskTitle'  => $title,
            'selection'  => $selection,
            'choice'     => $choice,
            'other'      => $other,
            'errors'     => $errors,
            'locked'     => $locked,
            'nextLabel'  => mb_strtolower(BillingMonth::label(BillingMonth::next($month), $locale)),
            'min'        => $bounds['min'],
            'max'        => $bounds['max'],
            'backUrl'    => '/invoices/new?' . http_build_query(array_filter($selection, static fn (string $v): bool => $v !== '')),
        ];

        $fragment = 'invoicing/_move_sheet';

        if (is_htmx_fragment_request() && service('request')->getHeaderLine('HX-Target') === 'sheet-body') {
            $fragment = 'invoicing/_move_form';
        }

        return $this->response->setStatusCode($status)
            ->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view($fragment, $data, 'invoicing/move_page'));
    }

    private static function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h    = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private static function languageName(string $code): string
    {
        $key = 'Invoicing.lang_' . $code;
        $out = lang($key);

        return $out === $key ? $code : $out;
    }

    private function render(bool $fragmentRoute): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        return $this->renderPreview($fragmentRoute, PreviewInput::fromGet($request));
    }

    /**
     * @param array<string, string>                                                   $raw
     * @param list<array{message: string, link?: array{href: string, label: string}}> $alerts
     * @param array<string, string>                                                   $payErrors
     */
    private function renderPreview(bool $fragmentRoute, array $raw, int $forcedStatus = 0, array $alerts = [], array $payErrors = []): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $errors  = PreviewInput::validate($raw);
        $clients = (new ClientService())->listAll();
        $bounds  = PreviewInput::monthBounds();
        $data    = [
            'clients'    => $clients,
            'values'     => $raw,
            'errors'     => $errors,
            'draft'      => null,
            'bounds'     => $bounds,
            'locale'     => $request->getLocale(),
            'notice'     => null,
            'refresh'    => '',
            'entities'   => [],
            'monthLabel' => '',
            'clientName' => '',
            'requestKey' => self::uuid4(),
            'alerts'     => $alerts,
            'payErrors'  => $payErrors,
        ];

        $status = 200;

        if ($errors !== []) {
            $status = 422;
        } elseif ($clients !== []) {
            $ids      = array_map(static fn (array $c): int => (int) $c['id'], $clients);
            $wanted   = $raw['client'] === '' ? null : (int) $raw['client'];
            $default  = (new LastClient())->resolve();
            $clientId = $wanted !== null && in_array($wanted, $ids, true) ? $wanted : ($default !== null && in_array($default, $ids, true) ? $default : $ids[0]);

            $service = new InvoiceDraftService();
            $month   = $raw['month'] !== '' ? $raw['month'] : $service->defaultMonth($clientId);
            $draft   = $service->draft(
                $clientId,
                $month,
                $raw['entity'] === '' ? null : (int) $raw['entity'],
                $raw['issue_date'] === '' ? null : $raw['issue_date'],
                $raw['delivery_date'] === '' ? null : $raw['delivery_date'],
                $raw['due_date'] === '' ? null : $raw['due_date'],
                $raw['payment_method'] ?? null,
                $raw['payment_note'] ?? null,
                ($raw['payment_note_dirty'] ?? '0') === '1',
            );

            $data['draft']      = $draft;
            $data['entities']   = $draft->entities;
            $data['monthLabel'] = mb_strtolower(BillingMonth::label($month, $request->getLocale()));
            $data['values']     = $this->selection($draft);
            $data['refresh']    = '/fragments/invoice-preview?' . http_build_query($data['values']);

            foreach ($clients as $c) {
                if ((int) $c['id'] === $clientId) {
                    $data['clientName'] = (string) $c['name'];
                }
            }
        }

        if ($forcedStatus !== 0 && $errors === []) {
            $status = $forcedStatus;
        }

        if ($fragmentRoute && is_htmx_fragment_request()) {
            $data['notice'] = session()->getFlashdata('app_notice');
        }

        return $this->response->setStatusCode($status)
            ->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view('invoicing/_preview', $data, 'invoicing/index'));
    }

    /**
     * @return array<string, string>
     */
    private function selection(Draft $draft): array
    {
        return [
            'client'             => (string) $draft->clientId,
            'month'              => $draft->month,
            'entity'             => $draft->entityId === null ? '' : (string) $draft->entityId,
            'issue_date'         => $draft->issueDate,
            'delivery_date'      => $draft->deliveryDate,
            'due_date'           => $draft->dueDate,
            'payment_method'     => $draft->paymentMethod,
            'payment_note'       => $draft->paymentNoteDirty ? (string) $draft->paymentNote : '',
            'payment_note_dirty' => $draft->paymentNoteDirty ? '1' : '0',
        ];
    }
}
