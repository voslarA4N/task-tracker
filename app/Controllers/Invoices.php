<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Export\CsvWriter;
use App\Libraries\Export\DocumentListExport;
use App\Libraries\Export\ExportFilename;
use App\Libraries\Export\ExportResponse;
use App\Libraries\Export\ExportTable;
use App\Libraries\Export\XlsxWriter;
use App\Libraries\Invoicing\DocumentFilename;
use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\InvoiceFilter;
use App\Libraries\Invoicing\InvoiceQueries;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Receivables\InvoiceStatusQueries;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

final class Invoices extends BaseController
{
    public function index(): ResponseInterface
    {
        $filter  = InvoiceFilter::fromQuery($this->request->getGet());
        $today   = service('clock')->today();
        $result  = (new InvoiceQueries())->list(100, $filter->status, $today, $filter);
        $client  = new ClientService();
        $clients = array_merge(
            array_map(static fn (array $c): array => $c + ['is_archived' => false], $client->listAll(false)),
            array_map(static fn (array $c): array => $c + ['is_archived' => true], $client->listAll(true)),
        );

        usort($clients, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        $fmt = $this->dateFormatter();

        $rows = array_map(static function (array $r) use ($fmt): array {
            $r['issue_date_label']    = self::label($fmt, $r['issue_date'] ?? null);
            $r['turnover_date_label'] = self::label($fmt, $r['turnover_date'] ?? null);
            $r['due_date_label']      = self::label($fmt, $r['due_date'] ?? null);

            return $r;
        }, $result['rows']);

        $body = htmx_view('invoices/_list', [
            'rows'      => $rows,
            'truncated' => $result['truncated'],
            'status'    => (string) $filter->status,
            'filter'    => $filter,
            'clients'   => $clients,
            'entities'  => (new EntityDirectory())->forSelect($today),
            'mainWide'  => true,
        ], 'invoices/index');

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    /**
     * D-08: export aktualne filtrovaneho a zotriedeneho zoznamu (bez limitu obrazovky).
     */
    public function exportCsv(): ResponseInterface
    {
        [$table, $name] = $this->exportTable('csv');

        return ExportResponse::attachment($this->response, CsvWriter::render($table), 'text/csv; charset=UTF-8', $name);
    }

    public function exportXlsx(): ResponseInterface
    {
        [$table, $name] = $this->exportTable('xlsx');

        return ExportResponse::attachment($this->response, XlsxWriter::render($table), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name);
    }

    /**
     * @return array{0: ExportTable, 1: string}
     */
    private function exportTable(string $ext): array
    {
        $filter = InvoiceFilter::fromQuery($this->request->getGet());
        $today  = service('clock')->today();
        $result = (new InvoiceQueries())->list(null, $filter->status, $today, $filter);
        $entity = 'vsetky';

        if ($filter->entityId !== null) {
            foreach ((new EntityDirectory())->forSelect($today) as $e) {
                if ($e['id'] === $filter->entityId) {
                    $entity = $e['name_internal'];
                    break;
                }
            }
        }

        return [
            DocumentListExport::table($result['rows'], false),
            ExportFilename::make(lang('Export.filePrefixInvoices'), [$entity, $today], $ext),
        ];
    }

    public function show(int $id): string
    {
        $invoice = (new InvoiceQueries())->detail($id);

        if ($invoice === null) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        $fmt   = $this->dateFormatter();
        $dates = [];

        foreach (['issue_date', 'delivery_date', 'due_date', 'credit_note_issue_date', 'corrected_issue_date'] as $field) {
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
        $row = db_connect()->query('SELECT origin, type, number, doc_language, snapshot_schema FROM invoices WHERE id = :id:', ['id' => $id])->getRowArray();

        if ($row === null || $row['origin'] !== 'issued') {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        $isCreditNote = $row['type'] === 'credit_note';

        // D-18: dobropis nema vykaz prace
        if ($isCreditNote && $kind === 'report') {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.reportMissing'));
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

        $filename    = DocumentFilename::for($isCreditNote && $kind === 'invoice' ? 'credit_note' : $kind, trim((string) $row['doc_language']), (string) $row['number']);
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
