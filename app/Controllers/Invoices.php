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
use App\Libraries\Invoicing\RegenerateRejected;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Platform\Clock;
use App\Libraries\Receivables\InvoiceStatusQueries;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeZone;
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

        $tz  = new DateTimeZone('Europe/Bratislava');
        $dtf = new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, 'Europe/Bratislava');

        $dates['regen_invoice_deadline'] = '';

        if (is_string($invoice['regen_invoice_deadline'] ?? null)) {
            $dates['regen_invoice_deadline'] = (string) $dtf->format(Clock::parseInstant($invoice['regen_invoice_deadline'])->setTimezone($tz));
        }

        $dates['versions'] = [];

        foreach ($invoice['document_versions'] as $v) {
            $dates['versions'][$v['kind'] . '-' . $v['version']] = (string) $dtf->format(Clock::parseInstant((string) $v['created_at'])->setTimezone($tz));
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

    public function pdfVersion(int $id, int $version): ResponseInterface
    {
        return $this->serve($id, 'invoice', $version);
    }

    public function reportVersion(int $id, int $version): ResponseInterface
    {
        return $this->serve($id, 'report', $version);
    }

    public function regenerateReport(int $id): RedirectResponse
    {
        return $this->regenerate($id, 'report');
    }

    public function regenerateInvoice(int $id): RedirectResponse
    {
        return $this->regenerate($id, 'invoice');
    }

    private function regenerate(int $id, string $kind): RedirectResponse
    {
        $expected = $this->request->getPost('expected_version');

        if (! is_string($expected) || ! ctype_digit($expected) || strlen($expected) > 9) {
            return $this->regenNotice($id, 'danger', lang('Invoices.regenErrorInput'));
        }

        try {
            $r = service('documentRegenerator')->regenerate($id, $kind, (int) $expected);
        } catch (RegenerateRejected $e) {
            return match ($e->reason) {
                'not_found'      => throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound')),
                'stale'          => $this->regenNotice($id, 'warning', lang('Invoices.regenErrorStale')),
                'window_expired' => $this->regenNotice($id, 'danger', lang('Invoices.regenErrorWindow')),
                'render_failed'  => $this->regenNotice($id, 'danger', lang('Invoices.regenErrorRender')),
                default          => $this->regenNotice($id, 'danger', lang('Invoices.regenErrorNotRegenerable')),
            };
        }

        $type  = (string) (db_connect()->query('SELECT type FROM invoices WHERE id = :id:', ['id' => $id])->getRowArray()['type'] ?? 'invoice');
        $label = lang($kind === 'report' ? 'Invoices.versionsKindReport' : ($type === 'credit_note' ? 'Invoices.versionsKindCreditNote' : 'Invoices.versionsKindInvoice'));

        return $this->regenNotice($id, 'success', lang('Invoices.regenDone', [$label, $r->version, $r->templateVersion]));
    }

    private function regenNotice(int $id, string $type, string $message): RedirectResponse
    {
        session()->setFlashdata('app_notice', ['type' => $type, 'message' => $message]);

        return redirect()->to('/invoices/' . $id);
    }

    private function serve(int $id, string $kind, ?int $version = null): ResponseInterface
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
            $store = new DocumentStore();
            $doc   = $version === null ? $store->read($id, $kind) : $store->readVersion($id, $kind, $version);
        } catch (DocumentIntegrityError $e) {
            log_message('critical', 'Invoice {id} {kind} PDF failed integrity check: {message}', ['id' => $id, 'kind' => $kind, 'message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setBody(lang($errorKey));
        }

        if ($doc === null && $version !== null) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        if ($doc === null) {
            if ($kind === 'report' && (int) $row['snapshot_schema'] < 2) {
                throw PageNotFoundException::forPageNotFound(lang('Invoices.reportMissing'));
            }

            log_message('critical', 'Invoice {id} has no stored {kind} PDF.', ['id' => $id, 'kind' => $kind]);

            return $this->response->setStatusCode(500)->setBody(lang($errorKey));
        }

        $filename = DocumentFilename::for($isCreditNote && $kind === 'invoice' ? 'credit_note' : $kind, trim((string) $row['doc_language']), (string) $row['number']);

        if ($version !== null) {
            $filename = (string) preg_replace('/\.pdf$/', '-v' . $version . '.pdf', $filename);
        }

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
