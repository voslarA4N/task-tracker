<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Master\EntityDirectory;
use App\Libraries\Reporting\ReportCalculator;
use App\Libraries\Reporting\ReportPeriod;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Prehlady (RPT-01): vyfakturovane po klientoch za mesiac a subjekt. Sumy pocita iba ReportCalculator.
 */
final class Reports extends BaseController
{
    public function month(): ResponseInterface
    {
        $today   = service('clock')->today();
        $current = substr($today, 0, 7);
        $raw     = $this->request->getGet('period');

        if ($raw === null || $raw === '') {
            $period = $current;
        } else {
            $period = BillingMonth::parse((string) $raw);

            if ($period === null) {
                return redirect()->to('/reports/month?' . http_build_query(['period' => $current]));
            }
        }

        $directory = new EntityDirectory();
        $entities  = $directory->forSelect($today);
        $locale    = $this->request->getLocale();
        $base      = [
            'entities'    => $entities,
            'entityId'    => null,
            'entity'      => null,
            'result'      => null,
            'periodLabel' => BillingMonth::label($period, $locale),
            'period'      => $period,
            'prevUrl'     => null,
            'nextUrl'     => null,
            'locale'      => $locale,
            'mainWide'    => true,
        ];

        if ($entities === []) {
            return $this->render($base);
        }

        $entity = $this->pickEntity($entities, $directory->activeAt($today), (string) $this->request->getGet('entity'));
        $report = ReportPeriod::month($period);
        $result = (new ReportCalculator())->forPeriod($entity['id'], $report);

        $url  = static fn (ReportPeriod $p): string => '/reports/month?' . http_build_query(['entity' => $entity['id'], 'period' => $p->key]);
        $next = $report->next();

        return $this->render([
            'entityId' => $entity['id'],
            'entity'   => $entity,
            'result'   => $result,
            'prevUrl'  => $url($report->previous()),
            'nextUrl'  => $next->isAfterMonth($current) ? null : $url($next),
        ] + $base);
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
    private function render(array $data): ResponseInterface
    {
        $body = htmx_view('reports/_month_body', $data, 'reports/month');

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }
}
