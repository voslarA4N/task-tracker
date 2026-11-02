<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\CurrencyRepository;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Reporting\LimitInput;
use App\Libraries\Reporting\LimitRejected;
use App\Libraries\Reporting\LimitSelector;
use App\Libraries\Reporting\LimitService;
use App\Libraries\Reporting\PeriodResolver;
use App\Libraries\Reporting\TurnoverCalculator;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Nastavenia, Limity obratu: cerpanie za aktivne subjekty a zoznam limitov (LIM-01, LIM-02, LIM-05).
 * Cisla pocita vylucne TurnoverCalculator (D-08), tu sa iba zbieraju data pre view.
 */
final class Limits extends BaseController
{
    public function index(): string
    {
        $today  = service('clock')->today();
        $limits = (new LimitSelector())->all();

        $activeFrom = [];

        foreach ((new EntityDirectory())->listAll($today) as $entity) {
            $activeFrom[$entity['id']] = $entity['active_from'];
        }

        return view('settings/limits/index', [
            'entities'   => (new TurnoverCalculator())->forActiveEntities($today),
            'limits'     => $limits,
            'hasSeed'    => array_filter($limits, static fn (array $l): bool => $l['is_seed']) !== [],
            'activeFrom' => $activeFrom,
            'locale'     => $this->request->getLocale(),
            'today'      => $today,
        ]);
    }

    public function newForm(): ResponseInterface
    {
        $today   = service('clock')->today();
        $country = $this->request->getGet('country');
        $country = is_string($country) && in_array($country, LimitInput::COUNTRIES, true) ? $country : 'SK';
        $year    = $this->request->getGet('year');
        $year    = is_string($year) && preg_match('/^\d{4}$/D', $year) === 1 && (int) $year >= 2000 && (int) $year <= 2100
            ? $year
            : substr($today, 0, 4);

        $values = [
            'country'     => $country,
            'year'        => $year,
            'name'        => '',
            'amount'      => '',
            'currency'    => ['SK' => 'EUR', 'PL' => 'PLN', 'CZ' => 'CZK'][$country],
            'calc_method' => 'calendar_year',
            'thresholds'  => '80, 100',
        ];

        return $this->render(true, $this->formData(null, $values, [], null, false));
    }

    public function create(): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $values = LimitInput::fromPost($request);

        try {
            (new LimitService())->create($values);
        } catch (LimitRejected $e) {
            return $this->rejected($e, null, $values);
        }

        return $this->saved($values['name']);
    }

    public function edit(int $id): ResponseInterface
    {
        $limit = $this->limitOr404($id);

        return $this->render(true, $this->formData($limit, $this->valuesOf($limit), [], null, $limit['is_seed']));
    }

    public function update(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $limit  = $this->limitOr404($id);
        $values = LimitInput::fromPost($request);

        try {
            (new LimitService())->update($id, $values);
        } catch (LimitRejected $e) {
            return $this->rejected($e, $limit, $values);
        }

        return $this->saved($values['name']);
    }

    public function deleteForm(int $id): ResponseInterface
    {
        $limit = $this->limitOr404($id);

        return $this->renderDelete(['limit' => $limit, 'error' => null]);
    }

    public function delete(int $id): ResponseInterface
    {
        $limit    = $this->limitOr404($id);
        $revision = $this->request->getPost('revision');

        try {
            (new LimitService())->delete($id, is_string($revision) && ctype_digit($revision) ? (int) $revision : 0);
        } catch (LimitRejected $e) {
            if ($e->reason === 'not_found') {
                throw PageNotFoundException::forPageNotFound();
            }

            $this->response->setStatusCode(409);

            return $this->renderDelete(['limit' => (new LimitService())->find($id) ?? $limit, 'error' => 'Limits.errStale']);
        }

        return $this->done(lang('Limits.flashDeleted', [$limit['name']]));
    }

    /**
     * @param array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int}|null $limit
     * @param array<string, string>                                                                                                                                                 $values
     */
    private function rejected(LimitRejected $e, ?array $limit, array $values): ResponseInterface
    {
        if ($e->reason === 'not_found') {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($e->reason === 'stale' && $limit !== null) {
            $fresh = (new LimitService())->find($limit['id']) ?? $limit;

            $this->response->setStatusCode(409);

            return $this->render(false, $this->formData($fresh, $this->valuesOf($fresh), [], 'Limits.errStale', $fresh['is_seed']));
        }

        $this->response->setStatusCode(422);

        return $this->render(false, $this->formData($limit, $values, $e->fields, null, $limit['is_seed'] ?? false));
    }

    private function saved(string $name): ResponseInterface
    {
        return $this->done(lang('Limits.flashSaved', [$name]));
    }

    private function done(string $message): ResponseInterface
    {
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => $message]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/limits')->setBody('');
        }

        return redirect()->to('/settings/limits');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(bool $get, array $data): ResponseInterface
    {
        $this->response->setHeader('Vary', 'HX-Request');
        $data['sheet'] = is_htmx_fragment_request();

        if ($get) {
            return $this->response->setBody($data['sheet'] ? view('settings/limits/_form_sheet', $data) : view('settings/limits/form', $data));
        }

        return $this->response->setBody(htmx_view('settings/limits/_form', $data, 'settings/limits/form'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderDelete(array $data): ResponseInterface
    {
        $this->response->setHeader('Vary', 'HX-Request');
        $data['sheet'] = is_htmx_fragment_request();

        return $this->response->setBody($data['sheet'] ? view('settings/limits/_delete_sheet', $data) : view('settings/limits/delete', $data));
    }

    /**
     * @param array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int}|null $limit
     * @param array<string, string>                                                                                                                                                 $values
     * @param array<string, string>                                                                                                                                                 $errors
     *
     * @return array<string, mixed>
     */
    private function formData(?array $limit, array $values, array $errors, ?string $formError, bool $isSeed): array
    {
        $values += ['country' => 'SK', 'year' => '', 'name' => '', 'amount' => '', 'currency' => 'EUR', 'calc_method' => 'calendar_year', 'thresholds' => '', 'revision' => ''];

        $country  = strtoupper($values['country']);
        $currency = strtoupper($values['currency']);
        $warning  = in_array($country, LimitInput::COUNTRIES, true) && preg_match('/^[A-Z]{3}$/D', $currency) === 1
            ? (new LimitService())->currencyWarning($country, $currency, service('clock')->today())
            : null;

        return [
            'limit'           => $limit,
            'values'          => $values,
            'errors'          => $errors,
            'formError'       => $formError,
            'isSeed'          => $isSeed,
            'currencyWarning' => $warning,
            'currencies'      => (new CurrencyRepository())->codes(),
            'methods'         => PeriodResolver::METHODS,
        ];
    }

    /**
     * @param array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int} $limit
     *
     * @return array<string, string>
     */
    private function valuesOf(array $limit): array
    {
        return [
            'country'     => $limit['country'],
            'year'        => (string) $limit['year'],
            'name'        => $limit['name'],
            'amount'      => $limit['amount'],
            'currency'    => $limit['currency'],
            'calc_method' => $limit['calc_method'],
            'thresholds'  => implode(', ', $limit['thresholds']),
            'revision'    => (string) $limit['revision'],
        ];
    }

    /**
     * @return array{id: int, country: string, year: int, name: string, amount: string, currency: string, calc_method: string, thresholds: list<int>, is_seed: bool, revision: int}
     */
    private function limitOr404(int $id): array
    {
        return (new LimitService())->find($id) ?? throw PageNotFoundException::forPageNotFound();
    }
}
