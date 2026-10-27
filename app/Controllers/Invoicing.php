<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Invoicing\Draft;
use App\Libraries\Invoicing\InvoiceDraftService;
use App\Libraries\Invoicing\PreviewInput;
use App\Libraries\Master\ClientService;
use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\LastClient;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

/**
 * Fakturacia mesiaca: vyber, nahlad (iba citanie) a presun casti z nahladu. Vystavenie dodava 05-09.
 */
final class Invoicing extends BaseController
{
    public function new(): ResponseInterface
    {
        return $this->render(false);
    }

    public function preview(): ResponseInterface
    {
        return $this->render(true);
    }

    /**
     * Mapovanie dovodu odmietnutia na text (D-10, D-18). Pre neznamy dovod vseobecna sprava.
     *
     * @param array<string, mixed> $context
     */
    public static function errorMessage(string $reason, array $context = []): string
    {
        $text = static fn (string $k): string => is_scalar($context[$k] ?? null) ? (string) $context[$k] : '';

        return match ($reason) {
            'mixed_currency' => lang('Invoicing.errorMixedCurrency', [
                is_array($context['currencies'] ?? null) ? implode(', ', array_map('strval', $context['currencies'])) : '',
            ]),
            'currency_mismatch'  => lang('Invoicing.errorCurrencyMismatch', [$text('currency'), $text('entity_currency')]),
            'legal_text_missing' => lang('Invoicing.errorLegalText', [self::languageName($text('language'))]),
            'no_bank_account'    => lang('Invoicing.errorNoBank', [$text('currency')]),
            'vat_code_missing'   => lang('Invoicing.errorVatCode'),
            'version_not_found'  => lang('Invoicing.errorVersion'),
            'amount_mismatch'    => lang('Invoicing.errorAmountMismatch'),
            'entity_inactive'    => lang('Invoicing.errorEntityInactive', [self::dateLabel($text('date'))]),
            default              => lang('Invoicing.errorGeneric'),
        };
    }

    public static function dateLabel(string $iso): string
    {
        if ($iso === '' || service('clock')->parseDate($iso) === null) {
            return $iso;
        }

        $fmt = new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Bratislava');

        return (string) $fmt->format(service('clock')->now()->modify($iso . ' 12:00:00'));
    }

    private static function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h    = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    private static function languageName(string $code): string
    {
        $key = 'Invoicing.lang_' . $code;
        $out = lang($key);

        return $out === $key ? $code : $out;
    }

    private function render(bool $fragmentRoute): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $raw     = PreviewInput::fromGet($request);
        $errors  = PreviewInput::validate($raw);
        $clients = (new ClientService())->listAll();
        $bounds  = PreviewInput::monthBounds();
        $data    = [
            'clients'    => $clients,
            'values'     => $raw,
            'errors'     => $errors,
            'draft'      => null,
            'bounds'     => $bounds,
            'locale'     => $request->getLocale(),
            'notice'     => null,
            'refresh'    => '',
            'entities'   => [],
            'monthLabel' => '',
            'clientName' => '',
            'requestKey' => self::uuid4(),
        ];

        $status = 200;

        if ($errors !== []) {
            $status = 422;
        } elseif ($clients !== []) {
            $ids      = array_map(static fn (array $c): int => (int) $c['id'], $clients);
            $wanted   = $raw['client'] === '' ? null : (int) $raw['client'];
            $default  = (new LastClient())->resolve();
            $clientId = $wanted !== null && in_array($wanted, $ids, true) ? $wanted : ($default !== null && in_array($default, $ids, true) ? $default : $ids[0]);

            $service = new InvoiceDraftService();
            $month   = $raw['month'] !== '' ? $raw['month'] : $service->defaultMonth($clientId);
            $draft   = $service->draft(
                $clientId,
                $month,
                $raw['entity'] === '' ? null : (int) $raw['entity'],
                $raw['issue_date'] === '' ? null : $raw['issue_date'],
                $raw['delivery_date'] === '' ? null : $raw['delivery_date'],
                $raw['due_date'] === '' ? null : $raw['due_date'],
            );

            $data['draft']      = $draft;
            $data['entities']   = $draft->entities;
            $data['monthLabel'] = mb_strtolower(BillingMonth::label($month, $request->getLocale()));
            $data['values']     = $this->selection($draft);
            $data['refresh']    = '/fragments/invoice-preview?' . http_build_query($data['values']);

            foreach ($clients as $c) {
                if ((int) $c['id'] === $clientId) {
                    $data['clientName'] = (string) $c['name'];
                }
            }
        }

        if ($fragmentRoute && is_htmx_fragment_request()) {
            $data['notice'] = session()->getFlashdata('app_notice');
        }

        return $this->response->setStatusCode($status)
            ->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view('invoicing/_preview', $data, 'invoicing/index'));
    }

    /**
     * @return array<string, string>
     */
    private function selection(Draft $draft): array
    {
        return [
            'client'        => (string) $draft->clientId,
            'month'         => $draft->month,
            'entity'        => $draft->entityId === null ? '' : (string) $draft->entityId,
            'issue_date'    => $draft->issueDate,
            'delivery_date' => $draft->deliveryDate,
            'due_date'      => $draft->dueDate,
        ];
    }
}
