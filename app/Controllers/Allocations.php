<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Platform\Hours;
use App\Libraries\Work\AllocationInput;
use App\Libraries\Work\AllocationLocked;
use App\Libraries\Work\AllocationService;
use App\Libraries\Work\BillingMonth;
use App\Libraries\Work\TaskQueries;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use NumberFormatter;

/**
 * Fakturacne casti ulohy v paneli zospodu (ALC-02, ALC-05). Zamknutu cast server nikdy nezmeni (409, D-09).
 */
final class Allocations extends BaseController
{
    public function new(int $taskId): ResponseInterface
    {
        $task   = $this->task($taskId);
        $values = [
            'month_choice'        => 'current',
            'billing_month_other' => '',
            'hours'               => '',
            'rate'                => (string) $task['rate'],
        ];

        return $this->panel(200, $task, null, 'add', $values, [], false);
    }

    public function create(int $taskId): ResponseInterface
    {
        $task    = $this->task($taskId);
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $raw    = AllocationInput::fromPost($request);
        $errors = AllocationInput::validate($raw);

        if ($errors !== []) {
            return $this->panel(422, $task, null, 'add', $this->formValues($raw), $errors, false);
        }

        (new AllocationService())->add($taskId, AllocationInput::normalize($raw));

        return $this->done($taskId, 'Allocations.added');
    }

    public function edit(int $id): ResponseInterface
    {
        [$allocation, $task] = $this->load($id);
        $values              = $this->formValues([
            'hours'         => Hours::format((string) $allocation['hours'], $this->locale()),
            'billing_month' => (string) $allocation['billing_month'],
            'rate'          => (string) $allocation['rate'],
        ]);

        return $this->panel($allocation['invoice_id'] === null ? 200 : 409, $task, $allocation, 'edit', $values, [], $allocation['invoice_id'] !== null);
    }

    public function update(int $id): ResponseInterface
    {
        [$allocation, $task] = $this->load($id);
        $request             = $this->request;
        assert($request instanceof IncomingRequest);

        $raw    = AllocationInput::fromPost($request);
        $errors = AllocationInput::validate($raw);

        if ($errors !== []) {
            return $this->panel(422, $task, $allocation, 'edit', $this->formValues($raw), $errors, false);
        }

        try {
            (new AllocationService())->update($id, AllocationInput::normalize($raw));
        } catch (AllocationLocked) {
            return $this->panel(409, $task, $allocation, 'edit', $this->formValues($raw), [], true);
        } catch (InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound(lang('Allocations.notFound'));
        }

        return $this->done((int) $task['id'], 'Allocations.savedFlash');
    }

    public function confirmDelete(int $id): ResponseInterface
    {
        [$allocation, $task] = $this->load($id);

        return $this->panel($allocation['invoice_id'] === null ? 200 : 409, $task, $allocation, 'delete', [], [], $allocation['invoice_id'] !== null);
    }

    public function delete(int $id): ResponseInterface
    {
        [$allocation, $task] = $this->load($id);

        try {
            (new AllocationService())->delete($id);
        } catch (AllocationLocked) {
            return $this->panel(409, $task, $allocation, 'delete', [], [], true);
        } catch (InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound(lang('Allocations.notFound'));
        }

        return $this->done((int) $task['id'], 'Allocations.deletedFlash');
    }

    /**
     * Uspech: htmx dostane HX-Location na detail a udalost allocationChanged (nie 302, D-15), klasicky POST presmerovanie.
     */
    private function done(int $taskId, string $flashKey): ResponseInterface
    {
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang($flashKey)]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)
                ->setHeader('HX-Location', '/tasks/' . $taskId)
                ->setHeader('HX-Trigger', 'allocationChanged')
                ->setBody('');
        }

        return redirect()->to('/tasks/' . $taskId);
    }

    /**
     * @param array<string, mixed>|null $allocation
     * @param array<string, string>     $values
     * @param array<string, string>     $errors
     * @param array<string, mixed>      $task
     */
    private function panel(int $status, array $task, ?array $allocation, string $mode, array $values, array $errors, bool $lockedError): ResponseInterface
    {
        $locale = $this->locale();
        $bounds = BillingMonth::rangeBounds();
        $month  = BillingMonth::current();
        $next   = BillingMonth::next($month);

        $data = [
            'task'        => $task,
            'allocation'  => $allocation,
            'values'      => $values,
            'errors'      => $errors,
            'mode'        => $mode,
            'lockedError' => $lockedError,
            'inner'       => $mode === 'delete' ? 'tasks/_alloc_delete' : 'tasks/_alloc_form',
            'format'      => $this->numberFormat((string) $task['currency']),
            'months'      => [
                'current'      => $month,
                'next'         => $next,
                'currentLabel' => mb_strtolower(BillingMonth::label($month, $locale)),
                'nextLabel'    => mb_strtolower(BillingMonth::label($next, $locale)),
                'min'          => $bounds['min'],
                'max'          => $bounds['max'],
            ],
        ];

        // Formular a potvrdenie cielia #sheet-body (innerHTML), tam patri iba vnutorny obsah panelu.
        $fragment = is_htmx_fragment_request() && service('request')->getHeaderLine('HX-Target') === 'sheet-body'
            ? $data['inner']
            : 'tasks/_sheet';

        return $this->response->setStatusCode($status)
            ->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view($fragment, $data, 'tasks/sheet_page'));
    }

    /**
     * @param array<string, string> $raw hodiny, billing_month, rate (surove)
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
            'rate'                => $raw['rate'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function task(int $taskId): array
    {
        $task = (new TaskQueries())->detail($taskId);

        if ($task === null) {
            throw PageNotFoundException::forPageNotFound(lang('Tasks.notFound'));
        }

        return $task;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function load(int $id): array
    {
        $allocation = (new AllocationService())->find($id);

        if ($allocation === null) {
            throw PageNotFoundException::forPageNotFound(lang('Allocations.notFound'));
        }

        return [$allocation, $this->task((int) $allocation['task_id'])];
    }

    /**
     * Oddelovace a symbol meny pre zivy prepocet v prehliadaci (rovnake ako MoneyFormat).
     *
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
