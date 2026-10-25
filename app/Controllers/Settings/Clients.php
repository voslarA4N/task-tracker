<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\BillingMode;
use App\Libraries\Master\ClientInput;
use App\Libraries\Master\ClientService;
use App\Libraries\Platform\Money;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Locale;
use NumberFormatter;

final class Clients extends BaseController
{
    public function index(): string
    {
        $archived = $this->request->getGet('archived') === '1';
        $rows     = (new ClientService())->listAll($archived);

        foreach ($rows as &$row) {
            $row['summary'] = implode(' · ', [
                $this->countryName((string) $row['country']),
                lang('Clients.perHour', [$this->formatRate((string) $row['hourly_rate'], (string) $row['currency'])]),
                BillingMode::tryFrom((string) $row['billing_mode'])?->label() ?? (string) $row['billing_mode'],
            ]);
        }
        unset($row);

        return view('settings/clients/index', ['title' => lang('Clients.listTitle'), 'clients' => $rows, 'archived' => $archived]);
    }

    public function new(): string
    {
        return view('settings/clients/form', $this->formData(null, $this->defaults(), []));
    }

    public function create(): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $values = ClientInput::fromPost($request);
        $errors = ClientInput::validate($values);

        if ($errors !== []) {
            return $this->response->setStatusCode(422)->setBody(view('settings/clients/form', $this->formData(null, $values, $errors)));
        }

        (new ClientService())->create(ClientInput::normalize($values));
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Clients.saved')]);

        return redirect()->to('/settings/clients');
    }

    public function edit(int $id): string
    {
        $client = (new ClientService())->find($id);

        if ($client === null) {
            throw PageNotFoundException::forPageNotFound(lang('Clients.notFound'));
        }

        return view('settings/clients/form', $this->formData($id, $this->toFormValues($client), []));
    }

    public function update(int $id): ResponseInterface
    {
        $service = new ClientService();

        if ($service->find($id) === null) {
            throw PageNotFoundException::forPageNotFound(lang('Clients.notFound'));
        }

        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $values = ClientInput::fromPost($request);
        $errors = ClientInput::validate($values);

        if ($errors !== []) {
            return $this->response->setStatusCode(422)->setBody(view('settings/clients/form', $this->formData($id, $values, $errors)));
        }

        $service->update($id, ClientInput::normalize($values));
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Clients.saved')]);

        return redirect()->to('/settings/clients');
    }

    public function archive(int $id): ResponseInterface
    {
        return $this->toggleArchive($id, true);
    }

    public function unarchive(int $id): ResponseInterface
    {
        return $this->toggleArchive($id, false);
    }

    public function portalPreview(): ResponseInterface
    {
        $tpl  = trim((string) $this->request->getGet('portal_url_template'));
        $data = $this->formData(null, array_merge($this->defaults(), ['portal_url_template' => $tpl]), []);

        return $this->response->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view('settings/clients/_portal_preview', $data, 'settings/clients/form'));
    }

    private function toggleArchive(int $id, bool $archive): ResponseInterface
    {
        $service = new ClientService();

        if ($service->find($id) === null) {
            throw PageNotFoundException::forPageNotFound(lang('Clients.notFound'));
        }

        $archive ? $service->archive($id) : $service->unarchive($id);
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang($archive ? 'Clients.archivedFlash' : 'Clients.unarchivedFlash')]);

        return redirect()->to('/settings/clients');
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function formData(?int $id, array $values, array $errors): array
    {
        $countries = [];

        foreach (ClientInput::COUNTRIES as $code) {
            $countries[$code] = $this->countryName($code);
        }

        asort($countries);

        $client = $id === null ? null : (new ClientService())->find($id);

        return [
            'title'     => lang($id === null ? 'Clients.newTitle' : 'Clients.editTitle'),
            'id'        => $id,
            'client'    => $client,
            'values'    => $values,
            'errors'    => $errors,
            'countries' => $countries,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $values = [];

        foreach (ClientInput::FIELDS as $field) {
            $values[$field] = '';
        }

        return array_merge($values, [
            'country'             => 'SK',
            'currency'            => 'EUR',
            'billing_mode'        => 'domestic',
            'identifier_type'     => 'local_id',
            'counts_toward_limit' => '1',
        ]);
    }

    /**
     * @param array<string, mixed> $client
     *
     * @return array<string, string>
     */
    private function toFormValues(array $client): array
    {
        $values = [];

        foreach (ClientInput::FIELDS as $field) {
            $value = $client[$field] ?? null;

            $values[$field] = match ($field) {
                'cc_emails'           => implode("\n", array_map('strval', (array) $value)),
                'counts_toward_limit' => $value === true ? '1' : '0',
                default               => $value === null ? '' : (string) $value,
            };
        }

        return $values;
    }

    private function countryName(string $code): string
    {
        $name = Locale::getDisplayRegion('-' . $code, service('request')->getLocale());

        return $name === '' ? $code : $name;
    }

    /**
     * Iba zobrazenie, bez float: cele cislo cez NumberFormatter, desatinna cast a symbol meny zlozene
     * podla vzoru lokality (suffix `1 080,00 €` alebo prefix `€1,080.00`).
     */
    private function formatRate(string $rate, string $currency): string
    {
        $locale             = service('request')->getLocale();
        $money              = Money::of($rate)->toDecimalString();
        [$whole, $fraction] = explode('.', $money) + [1 => '00'];

        $decimal = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $decimal->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $integer = $decimal->format((int) $whole);
        $number  = ($integer === false ? $whole : $integer) . $decimal->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL) . $fraction;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);
        $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return str_starts_with($formatter->getPattern(), '¤') ? $symbol . $number : $number . "\u{00A0}" . $symbol;
    }
}
