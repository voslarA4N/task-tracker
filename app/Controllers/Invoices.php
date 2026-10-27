<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\InvoiceQueries;
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

        return view('invoices/show', ['invoice' => $invoice, 'dates' => $dates]);
    }

    /**
     * D-16: vzdy ulozene bajty, nikdy regeneracia. Nezhoda sha256 alebo chybajuci dokument je 500 a critical log.
     */
    public function pdf(int $id): ResponseInterface
    {
        $row = db_connect()->query('SELECT origin, number FROM invoices WHERE id = :id:', ['id' => $id])->getRowArray();

        if ($row === null || $row['origin'] !== 'issued') {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        try {
            $doc = (new DocumentStore())->read($id);
        } catch (DocumentIntegrityError $e) {
            log_message('critical', 'Invoice {id} PDF failed integrity check: {message}', ['id' => $id, 'message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setBody(lang('Invoices.errorPdf'));
        }

        if ($doc === null) {
            log_message('critical', 'Invoice {id} has no stored PDF.', ['id' => $id]);

            return $this->response->setStatusCode(500)->setBody(lang('Invoices.errorPdf'));
        }

        $name        = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['number']);
        $disposition = $this->request->getGet('download') === '1' ? 'attachment' : 'inline';

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', $disposition . '; filename="faktura-' . $name . '.pdf"')
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
