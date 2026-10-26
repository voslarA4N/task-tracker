<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Platform\Hours;
use App\Libraries\Work\AllocationInput;
use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\TaskQueries;
use App\Libraries\Work\TaskRejected;
use App\Libraries\Work\TaskService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use NumberFormatter;

/**
 * Zmena stavu ulohy: zapracovanie s casťou v zvolenom mesiaci a navrat do stavu V praci (ALC-04, D-11).
 */
final class TaskStatus extends BaseController
{
    public function donePanel(int $id): ResponseInterface
    {
        $task = $this->task($id);

        if ((string) $task['status'] !== 'in_progress') {
            return $this->panel(409, $task, [], [], lang('Allocations.errorAlreadyDone'));
        }

        $values = [
            'month_choice'        => 'current',
            'billing_month_other' => '',
            'hours'               => Hours::format((new TaskService())->remainingHours($id), $this->locale()),
        ];

        return $this->panel(200, $task, $values, [], null);
    }

    public function markDone(int $id): ResponseInterface
    {
        $task    = $this->task($id);
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $raw         = AllocationInput::fromPost($request);
        $raw['rate'] = (string) $task['rate']; // sadzba z formulara sa ignoruje, cast berie sadzbu ulohy (T-04-52)
        $errors      = AllocationInput::validate($raw, true);

        if ($errors !== []) {
            unset($errors['rate']);
        }

        if ($errors !== []) {
            return $this->panel(422, $task, $this->formValues($raw), $errors, null);
        }

        $normalized = AllocationInput::normalize($raw);

        try {
            $allocationId = (new TaskService())->markDone($id, $normalized['billing_month'], $normalized['hours']);
        } catch (TaskRejected $e) {
            if ($e->reason === 'already_done') {
                return $this->panel(409, $task, $this->formValues($raw), [], lang('Allocations.errorAlreadyDone'));
            }

            throw $e;
        }

        $message = $allocationId === null
            ? lang('Allocations.doneFlashNoPart')
            : lang('Allocations.doneFlash', [
                Hours::format($normalized['hours'], $this->locale()),
                mb_strtolower(BillingMonth::label($normalized['billing_month'], $this->locale())),
            ]);
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => $message]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)
                ->setHeader('HX-Location', '/tasks/' . $id)
                ->setHeader('HX-Trigger', 'allocationChanged')
                ->setBody('');
        }

        return redirect()->to('/tasks/' . $id);
    }

    public function reopen(int $id): ResponseInterface
    {
        $task = $this->task($id);

        try {
            (new TaskService())->reopen($id);
        } catch (TaskRejected $e) {
            if ($e->reason === 'not_done') {
                $data = (new Tasks())->showData($task, lang('Allocations.errorNotDone'));

                return $this->response->setStatusCode(409)->setBody(view('tasks/show', $data));
            }

            throw $e;
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Allocations.reopenedFlash')]);

        return redirect()->to('/tasks/' . $id);
    }

    /**
     * @param array<string, mixed>  $task
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function panel(int $status, array $task, array $values, array $errors, ?string $conflict): ResponseInterface
    {
        $locale = $this->locale();
        $month  = BillingMonth::current();
        $next   = BillingMonth::next($month);
        $bounds = BillingMonth::rangeBounds();
        $values += ['month_choice' => 'current', 'billing_month_other' => '', 'hours' => ''];

        $remaining = $conflict === null ? (new TaskService())->remainingHours((int) $task['id']) : '0';

        $data = [
            'task'      => $task,
            'mode'      => 'done',
            'inner'     => 'tasks/_done_form',
            'values'    => $values,
            'errors'    => $errors,
            'conflict'  => $conflict,
            'zero'      => ! Hours::isPositive($remaining),
            'remaining' => Hours::format($remaining, $locale),
            'taskHours' => Hours::format((string) $task['hours'], $locale),
            'format'    => $this->numberFormat((string) $task['currency']),
            'months'    => [
                'currentLabel' => mb_strtolower(BillingMonth::label($month, $locale)),
                'nextLabel'    => mb_strtolower(BillingMonth::label($next, $locale)),
                'min'          => $bounds['min'],
                'max'          => $bounds['max'],
            ],
        ];

        $fragment = is_htmx_fragment_request() && service('request')->getHeaderLine('HX-Target') === 'sheet-body'
            ? $data['inner']
            : 'tasks/_sheet';

        return $this->response->setStatusCode($status)
            ->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view($fragment, $data, 'tasks/sheet_page'));
    }

    /**
     * @param array<string, string> $raw
     *
     * @return array<string, string>
     */
    private function formValues(array $raw): array
    {
        $month   = $raw['billing_month'] ?? '';
        $current = BillingMonth::current();
        $choice  = match (true) {
            $month === '' || $month === $current    => 'current',
            $month === BillingMonth::next($current) => 'next',
            default                                 => 'other',
        };

        return [
            'month_choice'        => $choice,
            'billing_month_other' => $choice === 'other' ? $month : '',
            'hours'               => $raw['hours'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(int $id): array
    {
        $task = (new TaskQueries())->detail($id);

        if ($task === null) {
            throw PageNotFoundException::forPageNotFound(lang('Tasks.notFound'));
        }

        return $task;
    }

    /**
     * @return array{symbol: string, decimal: string, group: string, symbolBefore: bool}
     */
    private function numberFormat(string $currency): array
    {
        $locale    = $this->locale();
        $decimal   = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return [
            'symbol'       => $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL),
            'decimal'      => $decimal->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            'group'        => $decimal->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL),
            'symbolBefore' => str_starts_with($formatter->getPattern(), '¤'),
        ];
    }

    private function locale(): string
    {
        return service('request')->getLocale();
    }
}
