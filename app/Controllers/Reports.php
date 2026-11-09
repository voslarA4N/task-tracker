<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Export\CsvWriter;
use App\Libraries\Export\ExportFilename;
use App\Libraries\Export\ExportResponse;
use App\Libraries\Export\ReportTable;
use App\Libraries\Export\XlsxWriter;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Prehlady (RPT-01): vyfakturovane po klientoch za mesiac a subjekt. Sumy pocita iba ReportCalculator.
 */
final class Reports extends BaseController
{
    public function month(): ResponseInterface
    {
        $ctx     = $this->context();
        $today   = $ctx['today'];
        $current = substr($today, 0, 7);
        $raw     = $this->request->getGet('period');

        if ($raw === null || $raw === '') {
            $period = $current;
        } else {
            $period = BillingMonth::parse((string) $raw);

            if ($period === null || ! ReportPeriod::isInBounds($period)) {
                return redirect()->to('/reports/month?' . http_build_query(['period' => $current]));
            }
        }

        $locale = $this->request->getLocale();
        $base   = [
            'entities'    => $ctx['entities'],
            'entityId'    => null,
            'entity'      => null,
            'result'      => null,
            'periodLabel' => BillingMonth::label($period, $locale),
            'period'      => $period,
            'prevUrl'     => null,
            'nextUrl'     => null,
            'locale'      => $locale,
            'mainWide'    => true,
            'view'        => 'month',
            'monthKey'    => $period,
            'yearKey'     => substr($period, 0, 4),
        ];

        $entity = $ctx['entity'];

        if ($entity === null) {
            return $this->render($base, 'reports/_month_body', 'reports/month');
        }

        $report = ReportPeriod::month($period);
        $result = (new ReportCalculator())->forPeriod($entity['id'], $report);

        $url    = static fn (ReportPeriod $p): string => '/reports/month?' . http_build_query(['entity' => $entity['id'], 'period' => $p->key]);
        $prevYm = BillingMonth::previous($period);
        $nextYm = BillingMonth::next($period);
        $next   = ReportPeriod::isInBounds($nextYm) ? ReportPeriod::month($nextYm) : null;

        return $this->render([
            'entityId' => $entity['id'],
            'entity'   => $entity,
            'result'   => $result,
            'prevUrl'  => ReportPeriod::isInBounds($prevYm) ? $url(ReportPeriod::month($prevYm)) : null,
            'nextUrl'  => $next === null || $next->isAfterMonth($current) ? null : $url($next),
        ] + $base, 'reports/_month_body', 'reports/month');
    }

    /**
     * Rocny pohlad (D-04): mesiace 1 az 12 a rozpad po klientoch.
     */
    public function year(): ResponseInterface
    {
        $ctx         = $this->context();
        $today       = $ctx['today'];
        $currentYear = (int) substr($today, 0, 4);
        $raw         = $this->request->getGet('year');

        if ($raw === null || $raw === '') {
            $year = $currentYear;
        } elseif (preg_match('/^\d{4}$/D', (string) $raw) === 1 && (int) $raw >= 2000 && (int) $raw <= 2100) {
            $year = (int) $raw;
        } else {
            return redirect()->to('/reports/year?' . http_build_query(['year' => $currentYear]));
        }

        $locale   = $this->request->getLocale();
        $yearKey  = sprintf('%04d', $year);
        $monthKey = $year === $currentYear ? substr($today, 0, 7) : $yearKey . '-01';
        $base     = [
            'entities'  => $ctx['entities'],
            'entityId'  => null,
            'entity'    => null,
            'result'    => null,
            'year'      => $year,
            'monthRows' => [],
            'prevUrl'   => null,
            'nextUrl'   => null,
            'locale'    => $locale,
            'mainWide'  => true,
            'view'      => 'year',
            'monthKey'  => $monthKey,
            'yearKey'   => $yearKey,
        ];

        $entity = $ctx['entity'];

        if ($entity === null) {
            return $this->render($base, 'reports/_year_body', 'reports/year');
        }

        $report = ReportPeriod::year($year);
        $result = (new ReportCalculator())->forPeriod($entity['id'], $report);

        $monthRows = [];

        foreach ($result->months as $row) {
            $monthRows[] = $row + [
                'label' => BillingMonth::label($row['month'], $locale),
                'href'  => '/reports/month?' . http_build_query(['entity' => $entity['id'], 'period' => $row['month']]),
            ];
        }

        $url = static fn (int $y): string => '/reports/year?' . http_build_query(['entity' => $entity['id'], 'year' => $y]);

        return $this->render([
            'entityId'  => $entity['id'],
            'entity'    => $entity,
            'result'    => $result,
            'monthRows' => $monthRows,
            'prevUrl'   => $year > 2000 ? $url($year - 1) : null,
            'nextUrl'   => $year >= $currentYear ? null : $url($year + 1),
        ] + $base, 'reports/_year_body', 'reports/year');
    }

    /**
     * CSV mesacneho prehladu (D-06 az D-08), rovnake cisla ako obrazovka.
     */
    public function monthCsv(): ResponseInterface
    {
        return $this->export('month', 'csv');
    }

    /**
     * CSV rocneho prehladu.
     */
    public function yearCsv(): ResponseInterface
    {
        return $this->export('year', 'csv');
    }

    /**
     * XLSX mesacneho prehladu (D-06), rovnaka tabulka ako CSV.
     */
    public function monthXlsx(): ResponseInterface
    {
        return $this->export('month', 'xlsx');
    }

    /**
     * XLSX rocneho prehladu.
     */
    public function yearXlsx(): ResponseInterface
    {
        return $this->export('year', 'xlsx');
    }

    /**
     * Spolocna priprava tabulky pre CSV aj XLSX, aby formaty nemohli divergovat.
     *
     * @param 'month'|'year' $view
     * @param 'csv'|'xlsx'   $format
     */
    private function export(string $view, string $format): ResponseInterface
    {
        $ctx    = $this->context();
        $entity = $ctx['entity'];

        if ($entity === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($view === 'month') {
            $period = BillingMonth::parse((string) $this->request->getGet('period'));

            if ($period === null || ! ReportPeriod::isInBounds($period)) {
                throw PageNotFoundException::forPageNotFound();
            }

            $report = ReportPeriod::month($period);
        } else {
            $raw = (string) $this->request->getGet('year');

            if (preg_match('/^\d{4}$/D', $raw) !== 1 || (int) $raw < 2000 || (int) $raw > 2100) {
                throw PageNotFoundException::forPageNotFound();
            }

            $report = ReportPeriod::year((int) $raw);
        }

        $result = (new ReportCalculator())->forPeriod($entity['id'], $report);
        $locale = $this->request->getLocale();
        $table  = $view === 'month' ? ReportTable::month($result, $locale) : ReportTable::year($result, $locale);
        $name   = ExportFilename::make(lang('Export.filePrefixReport'), [$report->key, $entity['name_internal']], $format);

        if ($format === 'xlsx') {
            return ExportResponse::attachment($this->response, XlsxWriter::render($table), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name);
        }

        return ExportResponse::attachment($this->response, CsvWriter::render($table), 'text/csv; charset=UTF-8', $name);
    }

    /**
     * Spolocny vyber subjektu pre mesacny aj rocny pohlad.
     *
     * @return array{today: string, entities: list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}>, entity: ?array{id: int, name_internal: string, country: string, currency: string, ended: bool}}
     */
    private function context(): array
    {
        $today     = service('clock')->today();
        $directory = new EntityDirectory();
        $entities  = $directory->forSelect($today);
        $entity    = $entities === [] ? null : $this->pickEntity($entities, $directory->activeAt($today), (string) $this->request->getGet('entity'));

        return ['today' => $today, 'entities' => $entities, 'entity' => $entity];
    }

    /**
     * @param list<array{id: int, name_internal: string, country: string, currency: string, ended: bool}> $entities
     * @param list<array{id: int, name_internal: string, country: string, currency: string}>              $active
     *
     * @return array{id: int, name_internal: string, country: string, currency: string, ended: bool}
     */
    private function pickEntity(array $entities, array $active, string $requested): array
    {
        if (preg_match('/^\d{1,18}$/D', $requested) === 1) {
            foreach ($entities as $e) {
                if ($e['id'] === (int) $requested) {
                    return $e;
                }
            }
        }

        foreach ($entities as $e) {
            if ($active !== [] && $e['id'] === $active[0]['id']) {
                return $e;
            }
        }

        return $entities[0];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data, string $fragment, string $page): ResponseInterface
    {
        $body = htmx_view($fragment, $data, $page);

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }
}
