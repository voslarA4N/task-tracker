<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\Country;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Master\EntityInput;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\VatRegime;
use App\Libraries\Master\VersionNotFound;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

final class Entities extends BaseController
{
    public function index(): string
    {
        $rows = (new EntityDirectory())->listAll(service('clock')->today());

        foreach ($rows as &$row) {
            $row['country_label'] = Country::tryFrom($row['country'])?->label() ?? $row['country'];
            $row['regime_label']  = VatRegime::tryFrom($row['vat_regime'])?->label() ?? lang('Entities.emptyValue');
            $from                 = $this->formatDate($row['active_from']);
            $row['period']        = $row['active_to'] === null
                ? lang('Entities.periodFrom', [$from])
                : $from . ' – ' . $this->formatDate($row['active_to']);
        }
        unset($row);

        return view('settings/entities/index', [
            'title'    => lang('Entities.listTitle'),
            'entities' => $rows,
        ]);
    }

    public function new(): string
    {
        $country = Country::SK;

        return view('settings/entities/new', $this->formData($this->defaults($country), [], $country));
    }

    public function regimeFields(): ResponseInterface
    {
        $country = Country::tryFrom(strtoupper((string) $this->request->getGet('country'))) ?? Country::SK;
        $values  = $this->defaults($country);

        return $this->response
            ->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view('settings/entities/_regime_fields', $this->formData($values, [], $country), 'settings/entities/new'));
    }

    public function create(): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $base    = EntityInput::baseFromPost($request);
        $version = EntityInput::normalizeVersion(EntityInput::versionFromPost($request));
        $country = Country::tryFrom($base['country']);

        $errors = EntityInput::validateBase($base, service('clock')->today());

        if ($country !== null) {
            $errors += EntityInput::validateVersion($version, $country);
        }

        $values = $base;

        foreach ($version as $key => $value) {
            $values[$key] = $value ?? '';
        }

        if ($errors !== []) {
            return $this->response
                ->setStatusCode(422)
                ->setBody(view('settings/entities/new', $this->formData($values, $errors, $country ?? Country::SK)));
        }

        $id = (new EntityService())->create($base, $version);

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Entities.created')]);

        return redirect()->to('/settings/entities/' . $id);
    }

    public function show(int $id): string
    {
        $entity = (new EntityService())->find($id);

        if ($entity === null) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        $today = service('clock')->today();

        try {
            $version = (new EntityVersionResolver())->current($id, $today);
        } catch (VersionNotFound) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        $country = Country::from((string) $entity['country']);
        $from    = (string) $entity['active_from'];
        $to      = $entity['active_to'] === null ? null : (string) $entity['active_to'];

        if ($from > $today) {
            $status = 'statusFuture';
        } elseif ($to !== null && $to < $today) {
            $status = 'statusEnded';
        } else {
            $status = 'statusActive';
        }

        return view('settings/entities/show', [
            'title'      => lang('Entities.detailTitle'),
            'entity'     => $entity,
            'version'    => $version,
            'country'    => $country,
            'status'     => $status,
            'validFrom'  => $this->formatDate((string) $version['valid_from']),
            'activeFrom' => $this->formatDate($from),
            'activeTo'   => $to === null ? null : $this->formatDate($to),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function defaults(Country $country): array
    {
        $values = ['name_internal' => '', 'country' => $country->value, 'currency' => $country->defaultCurrency()->value,
            'active_from'          => service('clock')->today(), 'active_to' => ''];

        foreach (EntityInput::VERSION_FIELDS as $field) {
            $values[$field] = '';
        }

        $values['doc_language'] = $country->defaultLanguage()->value;
        $values['vat_regime']   = $country->vatRegimes()[0]->value;
        $values['due_days']     = '14';

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function formData(array $values, array $errors, Country $country): array
    {
        return [
            'title'   => lang('Entities.newTitle'),
            'values'  => $values,
            'errors'  => $errors,
            'country' => $country,
        ];
    }

    private function formatDate(string $iso): string
    {
        $date = service('clock')->parseDate($iso);

        if ($date === null) {
            return $iso;
        }

        $formatter = new IntlDateFormatter(
            service('request')->getLocale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::GREGORIAN,
            'd. M. yyyy',
        );
        $out = $formatter->format($date->getTimestamp());

        return $out === false ? $iso : $out;
    }
}
