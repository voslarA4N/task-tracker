<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Export\AccountantBundle;
use App\Libraries\Export\BundleRejected;
use App\Libraries\Export\ExportFilename;
use App\Libraries\Export\ExportResponse;
use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Platform\MoneyFormat;
use App\Libraries\Reporting\BundleSelector;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Balik pre uctovnicku (RPT-03): ZIP s ulozenymi PDF a CSV zoznamom. Vsetky akcie su iba GET a iba pre admina.
 */
final class Accountant extends BaseController
{
    public function index(): ResponseInterface
    {
        $in = $this->input();

        return $this->page($in, $this->previewData($in), 'reports/accountant/index', 200);
    }

    public function preview(): ResponseInterface
    {
        $in = $this->input();

        return $this->page($in, $this->previewData($in), 'reports/accountant/_preview', $in['error'] === null ? 200 : 422);
    }

    public function download(): ResponseInterface
    {
        $in = $this->input();

        if ($in['entity'] === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($in['period'] === null) {
            return $this->page($in, $this->previewData($in), 'reports/accountant/index', 422);
        }

        try {
            $bytes = (new AccountantBundle())->build($in['entity']['id'], $in['period']);
        } catch (BundleRejected $e) {
            $text = match ($e->reason) {
                'missing_pdf' => lang('Bundle.errorMissingPdf', [count($e->numbers), implode(', ', $e->numbers)]),
                'too_large'   => lang('Bundle.errorTooLarge'),
                default       => lang('Bundle.empty'),
            };

            return $this->page($in, ['alertText' => $text] + $this->previewData($in), 'reports/accountant/index', 422);
        } catch (DocumentIntegrityError $e) {
            log_message('critical', 'Accountant bundle failed integrity check: {message}', ['message' => $e->getMessage()]);

            return $this->page($in, ['alertText' => lang('Bundle.errorBuild')] + $this->previewData($in), 'reports/accountant/index', 500);
        }

        $name = ExportFilename::make(lang('Export.filePrefixBundle'), [$in['entity']['name_internal'], $in['from'], $in['to']], 'zip');

        return ExportResponse::attachment($this->response, $bytes, 'application/zip', $name);
    }

    /**
     * @return array{entities: list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}>, entityId: ?int, entity: ?array{id: int, name_internal: string, country: string, currency: string, ended: bool}, from: string, to: string, period: ?ReportPeriod, error: ?string}
     */
    private function input(): array
    {
        $today     = service('clock')->today();
        $directory = new EntityDirectory();
        $entities  = $directory->forSelect($today);
        $entity    = null;
        $requested = (string) $this->request->getGet('entity');

        if ($entities !== []) {
            $entity = $entities[0];
            $wanted = preg_match('/^\d{1,18}$/D', $requested) === 1 ? (int) $requested : ($directory->activeAt($today)[0]['id'] ?? null);

            foreach ($entities as $e) {
                if ($e['id'] === $wanted) {
                    $entity = $e;

                    break;
                }
            }
        }

        $default = BillingMonth::previous(substr($today, 0, 7));
        $from    = BillingMonth::parse((string) $this->request->getGet('from')) ?? $default;
        $to      = BillingMonth::parse((string) $this->request->getGet('to')) ?? $default;
        $period  = null;
        $error   = null;

        try {
            $period = ReportPeriod::range($from, $to);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage() === 'too_long' ? 'Bundle.errorTooLong' : 'Bundle.errorOrder';
        }

        return ['entities' => $entities, 'entityId' => $entity['id'] ?? null, 'entity' => $entity, 'from' => $from, 'to' => $to, 'period' => $period, 'error' => $error];
    }

    /**
     * @param array{entity: ?array{id: int, name_internal: string, country: string, currency: string, ended: bool}, period: ?ReportPeriod, error: ?string} $in
     *
     * @return array{invoices: int, creditNotes: int, cancelled: int, turnover: ?string}
     */
    private function previewData(array $in): array
    {
        $data = ['invoices' => 0, 'creditNotes' => 0, 'cancelled' => 0, 'turnover' => null];

        if ($in['entity'] === null || $in['period'] === null) {
            return $data;
        }

        foreach ((new BundleSelector())->documents($in['entity']['id'], $in['period']) as $d) {
            if ($d['type'] === 'credit_note') {
                $data['creditNotes']++;
            } else {
                $data['invoices']++;
            }

            if ($d['status'] === 'cancelled') {
                $data['cancelled']++;
            }
        }

        $result           = (new ReportCalculator())->forPeriod($in['entity']['id'], $in['period']);
        $data['turnover'] = MoneyFormat::format($result->invoicedTotal, $result->currency, $this->request->getLocale());

        return $data;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed> $extra
     */
    private function page(array $in, array $extra, string $view, int $status): ResponseInterface
    {
        $body = $view === 'reports/accountant/_preview'
            ? htmx_view('reports/accountant/_preview', $in + $extra, 'reports/accountant/index')
            : view($view, $in + $extra + ['alertText' => null]);

        return $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request')->setBody($body);
    }
}
