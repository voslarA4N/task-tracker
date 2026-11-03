<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Master\ClientService;
use App\Libraries\Receivables\InvoiceStatusQueries;
use App\Libraries\Reporting\LimitAlerts;
use App\Libraries\Reporting\LimitStatus;
use App\Libraries\Reporting\TurnoverCalculator;
use App\Libraries\Work\DashboardFilter;
use App\Libraries\Work\LastClient;
use App\Libraries\Work\TaskQueries;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class Home extends BaseController
{
    public function index(): ResponseInterface
    {
        $data = $this->data();

        return $this->response->setBody(view('home/index', $data + ['title' => lang('App.title')]));
    }

    public function body(): ResponseInterface
    {
        $body = htmx_view('home/_body', $this->data(), 'home/index');

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $service = new ClientService();
        $get     = $request->getGet();
        $other   = $get['month_other'] ?? null;
        $month   = $get['month'] ?? null;

        // WR-03: vlastny mesiac plati iba pri explicitnej volbe "Iny" (alebo ak predvolba vobec nebola poslana),
        // inak by predvyplneny month_other prebil zvoleny predvoleny mesiac a navrat by nebol mozny.
        if (($month === 'other' || $month === null) && is_string($other) && $other !== '') {
            $get['month'] = $other;
        }

        $filter = DashboardFilter::fromQuery($get, (new LastClient())->resolve());
        $client = $filter->clientId !== null ? $service->find($filter->clientId) : null;

        // Neexistujuci alebo archivovany klient v URL -> Vsetci.
        if ($filter->clientId !== null && ($client === null || $client['archived_at'] !== null)) {
            $filter = new DashboardFilter($filter->month, null);
            $client = null;
        }

        return [
            'filter'  => $filter,
            'client'  => $client,
            'clients' => $service->listAll(false),
            'data'    => (new TaskQueries())->dashboard($filter->month, $filter->clientId),
            'overdue' => (new InvoiceStatusQueries())->overdue(service('clock')->today(), $filter->clientId, 10),
            // Limity su za subjekt, nezavisle od filtra klienta (D-11).
            'limits' => $this->limitAlerts(),
        ];
    }

    /**
     * WR-04: vedlajsi blok limitov nesmie zhodit prehlad; chyba vypoctu sa zaloguje a zobrazi ako jeden riadok.
     *
     * @return list<array{kind: 'config'|'crit'|'failed'|'info'|'warn', entityName: string, status: ?LimitStatus, refYear: int, usedYear: ?int, country: string}>
     */
    private function limitAlerts(): array
    {
        $today = service('clock')->today();

        try {
            return LimitAlerts::fromEntities((new TurnoverCalculator())->forActiveEntities($today));
        } catch (Throwable $e) {
            log_message('error', 'Dashboard limits failed: {class}: {message}', ['class' => $e::class, 'message' => $e->getMessage()]);

            return [LimitAlerts::failed((int) substr($today, 0, 4))];
        }
    }
}
