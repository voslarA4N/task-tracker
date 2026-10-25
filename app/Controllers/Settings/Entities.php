<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\BankAccountService;
use App\Libraries\Master\BillingMode;
use App\Libraries\Master\Country;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Master\EntityInput;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\LegalProfileResolver;
use App\Libraries\Master\LegalTextMissing;
use App\Libraries\Master\SeriesService;
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

        // Zmena krajiny prekreslí aj identitu (REGON, popis IČO/NIP), už vpísané hodnoty ostanú (WR-09).
        // Mena, DPH režim a jazyk dokladov sa nastavia podľa novej krajiny.
        foreach (EntityInput::VERSION_FIELDS as $field) {
            $raw = $this->request->getGet($field);

            if (is_string($raw) && ! in_array($field, ['vat_regime', 'doc_language'], true)) {
                $values[$field] = trim($raw);
            }
        }

        return $this->response
            ->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view('settings/entities/_country_fields', $this->formData($values, [], $country), 'settings/entities/new'));
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

    public function show(int $id, ?string $endError = null): string
    {
        $entity = $this->entityOr404($id);

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

        $history = (new EntityVersionResolver())->history($id, $today);

        foreach ($history as &$item) {
            $item['from_label'] = $this->formatDate($item['valid_from']);
        }
        unset($item);

        $bank          = new BankAccountService();
        $seriesService = new SeriesService();
        $series        = $seriesService->listFor($id);
        $archivedList  = $bank->listFor($id, true);
        $openArchived  = $this->request->getGet('archived') === '1';

        return view('settings/entities/show', [
            'legalRows'    => $this->legalRows($country, (string) $version['vat_regime'], (string) $version['doc_language']),
            'bankAccounts' => $bank->listFor($id, false),
            'series'       => $series,
            'seriesLocked' => array_map(static fn (array $row): bool => $seriesService->isFormatLocked((int) $row['id']), $series),
            'archivedList' => $archivedList,
            'openArchived' => $openArchived,
            'endError'     => $endError,
            'canEnd'       => $to === null || $to >= $today,
            'today'        => $today,
            'history'      => $history,
            'fieldLabels'  => $this->fieldLabels($country),
            'title'        => lang('Entities.detailTitle'),
            'entity'       => $entity,
            'version'      => $version,
            'country'      => $country,
            'status'       => $status,
            'validFrom'    => $this->formatDate((string) $version['valid_from']),
            'activeFrom'   => $this->formatDate($from),
            'activeTo'     => $to === null ? null : $this->formatDate($to),
        ]);
    }

    public function edit(int $id): string
    {
        $entity = $this->entityOr404($id);

        return view('settings/entities/edit', $this->editData($entity, [
            'name_internal' => (string) $entity['name_internal'],
            'active_from'   => (string) $entity['active_from'],
            'active_to'     => $entity['active_to'] === null ? '' : (string) $entity['active_to'],
        ], []));
    }

    public function update(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $entity = $this->entityOr404($id);
        $values = EntityInput::baseUpdateFromPost($request);

        if (EntityInput::hasImmutableKeys($request)) {
            return $this->response
                ->setStatusCode(422)
                ->setBody(view('settings/entities/edit', $this->editData($entity, $values, ['_form' => lang('Entities.errorImmutable')])));
        }

        $history = (new EntityVersionResolver())->history($id, service('clock')->today());
        $first   = $history === [] ? (string) $entity['active_from'] : (string) $history[array_key_last($history)]['valid_from'];
        $errors  = EntityInput::validateBaseUpdate($values, $first, service('clock')->today());

        if ($errors !== []) {
            return $this->response
                ->setStatusCode(422)
                ->setBody(view('settings/entities/edit', $this->editData($entity, $values, $errors)));
        }

        (new EntityService())->updateBase($id, $values);

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Entities.saved')]);

        return redirect()->to('/settings/entities/' . $id);
    }

    public function end(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $entity = $this->entityOr404($id);

        if (EntityInput::hasImmutableKeys($request)) {
            return $this->response->setStatusCode(422)->setBody($this->showWithError($id, lang('Entities.errorImmutable')));
        }

        $raw = $request->getPost('active_to');
        $to  = is_string($raw) ? EntityInput::parseDate(trim($raw)) : null;

        if ($to === null) {
            return $this->response->setStatusCode(422)->setBody($this->showWithError($id, lang('Entities.errorDate')));
        }

        if ($to < (string) $entity['active_from']) {
            return $this->response->setStatusCode(422)->setBody($this->showWithError($id, lang('Entities.errorActiveToBeforeFrom')));
        }

        (new EntityService())->end($id, $to);

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Entities.endedFlash', [$this->formatDate($to)])]);

        return redirect()->to('/settings/entities/' . $id);
    }

    /**
     * Pravny profil pre kazdy rezim fakturacie (len na citanie, D-16, D-17).
     *
     * @return list<array{label: string, code: ?string, body: ?string, provisional: bool, missing: bool}>
     */
    private function legalRows(Country $country, string $regime, string $language): array
    {
        $resolver = new LegalProfileResolver();
        $rows     = [];

        foreach (BillingMode::cases() as $mode) {
            try {
                $code   = $resolver->exemptionCode($country->value, $regime, $mode->value);
                $text   = $resolver->text($country->value, $regime, $mode->value, $language, 'vat_note');
                $rows[] = ['label' => $mode->label(), 'code' => $code, 'body' => $text['body'], 'provisional' => $text['provisional'], 'missing' => false];
            } catch (LegalTextMissing) {
                $rows[] = ['label' => $mode->label(), 'code' => null, 'body' => null, 'provisional' => false, 'missing' => true];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function entityOr404(int $id): array
    {
        $entity = (new EntityService())->find($id);

        if ($entity === null) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        return $entity;
    }

    /**
     * @param array<string, mixed>  $entity
     * @param array<string, string> $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function editData(array $entity, array $values, array $errors): array
    {
        return [
            'title'   => lang('Entities.editTitle'),
            'entity'  => $entity,
            'country' => Country::from((string) $entity['country']),
            'values'  => $values,
            'errors'  => $errors,
        ];
    }

    private function showWithError(int $id, string $message): string
    {
        return $this->show($id, $message);
    }

    /**
     * @return array<string, string>
     */
    private function fieldLabels(Country $country): array
    {
        $labels = [];

        foreach (EntityInput::VERSION_FIELDS as $field) {
            $labels[$field] = match ($field) {
                'trade_name'    => lang('Entities.tradeName'),
                'address_line1' => lang('Entities.addressLine1'),
                'address_line2' => lang('Entities.addressLine2'),
                'postal_code'   => lang('Entities.postalCode'),
                'city'          => lang('Entities.city'),
                'company_id'    => $country === Country::PL ? lang('Entities.companyIdPl') : lang('Entities.companyIdSk'),
                'regon'         => lang('Entities.regon'),
                'tax_id'        => lang('Entities.taxId'),
                'vat_id'        => lang('Entities.vatId'),
                'registry_note' => lang('Entities.registryNote'),
                'email'         => lang('Entities.email'),
                'phone'         => lang('Entities.phone'),
                'doc_language'  => lang('Entities.docLanguage'),
                'vat_regime'    => lang('Entities.vatRegime'),
                'due_days'      => lang('Entities.dueDays'),
            };
        }

        return $labels;
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
