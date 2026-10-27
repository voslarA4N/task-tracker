<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Invoicing\InvoiceQueries;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

final class Invoices extends BaseController
{
    public function index(): ResponseInterface
    {
        $result = (new InvoiceQueries())->list(100);
        $fmt    = $this->dateFormatter();

        $rows = array_map(static function (array $r) use ($fmt): array {
            $r['issue_date_label'] = self::label($fmt, $r['issue_date'] ?? null);

            return $r;
        }, $result['rows']);

        $body = htmx_view('invoices/_list', ['rows' => $rows, 'truncated' => $result['truncated']], 'invoices/index');

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    public function show(int $id): string
    {
        $invoice = (new InvoiceQueries())->detail($id);

        if ($invoice === null) {
            throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
        }

        $fmt   = $this->dateFormatter();
        $dates = [];

        foreach (['issue_date', 'delivery_date', 'due_date'] as $field) {
            $dates[$field] = self::label($fmt, $invoice[$field] ?? null);
        }

        return view('invoices/show', ['invoice' => $invoice, 'dates' => $dates]);
    }

    private function dateFormatter(): IntlDateFormatter
    {
        return new IntlDateFormatter(service('request')->getLocale(), IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Bratislava');
    }

    private static function label(IntlDateFormatter $fmt, mixed $date): string
    {
        if (! is_string($date) || $date === '') {
            return '';
        }

        return (string) $fmt->format(service('clock')->now()->modify($date . ' 12:00:00'));
    }
}
