<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Invoicing\DocumentFilename;
use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\InvoiceQueries;
use App\Libraries\Receivables\InvoiceStatusQueries;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

final class Invoices extends BaseController
{
    public function index(): ResponseInterface
    {
        $result = (new InvoiceQueries())->list(100);
        $fmt    = $this->dateFormatter();

        $rows = array_map(static function (array $r) use ($fmt): array {
            $r['issue_date_label'] = self::label($fmt, $r['issue_date'] ?? null);

            return $r;
        }, $result['rows']);

        $body = htmx_view('invoices/_list', ['rows' => $rows, 'truncated' => $result['truncated']], 'invoices/index');

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    public function show(int $id): string
    {
        $invoice = (new InvoiceQueries())->detail($id);

        if ($invoice === null) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        $fmt   = $this->dateFormatter();
        $dates = [];

        foreach (['issue_date', 'delivery_date', 'due_date'] as $field) {
            $dates[$field] = self::label($fmt, $invoice[$field] ?? null);
        }

        $payState = null;
        $payments = [];

        if ($invoice['origin'] === 'issued') {
            $queries  = new InvoiceStatusQueries();
            $payState = $queries->forInvoice($id, service('clock')->today());
            $payments = $queries->payments($id);
        }

        return view('invoices/show', ['invoice' => $invoice, 'dates' => $dates, 'payState' => $payState, 'payments' => $payments]);
    }

    /**
     * D-16: vzdy ulozene bajty, nikdy regeneracia. Nezhoda sha256 alebo chybajuci dokument je 500 a critical log.
     */
    public function pdf(int $id): ResponseInterface
    {
        return $this->serve($id, 'invoice');
    }

    /**
     * D-21: PDF vykaz z ulozenych bajtov (INV-13, ziadna regeneracia).
     */
    public function report(int $id): ResponseInterface
    {
        return $this->serve($id, 'report');
    }

    private function serve(int $id, string $kind): ResponseInterface
    {
        $row = db_connect()->query('SELECT origin, number, doc_language, snapshot_schema FROM invoices WHERE id = :id:', ['id' => $id])->getRowArray();

        if ($row === null || $row['origin'] !== 'issued') {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        $errorKey = $kind === 'report' ? 'Invoices.errorReport' : 'Invoices.errorPdf';

        try {
            $doc = (new DocumentStore())->read($id, $kind);
        } catch (DocumentIntegrityError $e) {
            log_message('critical', 'Invoice {id} {kind} PDF failed integrity check: {message}', ['id' => $id, 'kind' => $kind, 'message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setBody(lang($errorKey));
        }

        if ($doc === null) {
            if ($kind === 'report' && (int) $row['snapshot_schema'] < 2) {
                throw PageNotFoundException::forPageNotFound(lang('Invoices.reportMissing'));
            }

            log_message('critical', 'Invoice {id} has no stored {kind} PDF.', ['id' => $id, 'kind' => $kind]);

            return $this->response->setStatusCode(500)->setBody(lang($errorKey));
        }

        $filename    = DocumentFilename::for($kind, trim((string) $row['doc_language']), (string) $row['number']);
        $disposition = $this->request->getGet('download') === '1' ? 'attachment' : 'inline';

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', $disposition . '; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('ETag', '"' . $doc->sha256 . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Length', (string) strlen($doc->bytes))
            ->setBody($doc->bytes);
    }

    private function dateFormatter(): IntlDateFormatter
    {
        return new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Bratislava');
    }

    private static function label(IntlDateFormatter $fmt, mixed $date): string
    {
        if (! is_string($date) || $date === '') {
            return '';
        }

        return (string) $fmt->format(service('clock')->now()->modify($date . ' 12:00:00'));
    }
}
