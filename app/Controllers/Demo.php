<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Platform\Money;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use OverflowException;

final class Demo extends BaseController
{
    public function index(): string
    {
        return htmx_view('demo/_calculator', $this->data(['hours' => '', 'rate' => ''], [], null), 'demo/index');
    }

    public function calculate(): ResponseInterface|string
    {
        $values = [
            'hours' => $this->normalize($this->request->getPost('hours')),
            'rate'  => $this->normalize($this->request->getPost('rate')),
        ];

        $rules = [
            'hours' => [
                'label'  => lang('Demo.hours'),
                'rules'  => ['required', 'regex_match[/^\d{1,5}(\.\d{1,2})?$/]', 'greater_than[0]'],
                'errors' => ['regex_match' => lang('Demo.errorDecimal', [lang('Demo.hours')])],
            ],
            'rate' => [
                'label'  => lang('Demo.rate'),
                'rules'  => ['required', 'regex_match[/^\d{1,10}(\.\d{1,2})?$/]'],
                'errors' => ['regex_match' => lang('Demo.errorDecimal', [lang('Demo.rate')])],
            ],
        ];

        if (! $this->validateData($values, $rules)) {
            return $this->invalid($values, $this->validator->getErrors());
        }

        try {
            $result = Money::of($values['rate'])->timesHours($values['hours'])->toDecimalString();
        } catch (InvalidArgumentException|OverflowException) {
            return $this->invalid($values, ['hours' => lang('Demo.errorRange')]);
        }

        return htmx_view('demo/_calculator', $this->data($values, [], $result), 'demo/index');
    }

    private function normalize(mixed $value): string
    {
        return is_string($value) ? str_replace(',', '.', trim($value)) : '';
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function invalid(array $values, array $errors): ResponseInterface
    {
        return $this->response
            ->setStatusCode(422)
            ->setBody(htmx_view('demo/_calculator', $this->data($values, $errors, null), 'demo/index'));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function data(array $values, array $errors, ?string $result): array
    {
        return [
            'title'  => lang('Demo.title'),
            'values' => $values,
            'errors' => $errors,
            'result' => $result,
        ];
    }
}
