<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Master\ClientService;
use App\Libraries\Work\DashboardFilter;
use App\Libraries\Work\LastClient;
use App\Libraries\Work\TaskQueries;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;

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

        if (is_string($other) && $other !== '') {
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
        ];
    }
}
