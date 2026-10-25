<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Master\ClientService;
use App\Libraries\Master\PortalUrlTemplate;
use App\Libraries\Platform\Money;
use App\Libraries\Work\LastClient;
use App\Libraries\Work\TaskHasLockedAllocations;
use App\Libraries\Work\TaskInput;
use App\Libraries\Work\TaskQueries;
use App\Libraries\Work\TaskRejected;
use App\Libraries\Work\TaskService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;
use NumberFormatter;

final class Tasks extends BaseController
{
    public function new(): string
    {
        $clients = (new ClientService())->listAll(false);

        if ($clients === []) {
            return view('tasks/form', $this->formData(null, [], [], [], null));
        }

        $clientId = (new LastClient())->resolve();
        $selected = $this->pickClient($clients, $clientId);
        $values   = array_merge($this->emptyValues(), [
            'client_id' => (string) $selected['id'],
            'rate'      => (string) $selected['hourly_rate'],
        ]);

        return view('tasks/form', $this->formData(null, $values, [], $clients, null));
    }

    public function create(): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $values  = TaskInput::fromPost($request);
        $service = new ClientService();
        $clients = $service->listAll(false);
        $client  = $this->activeClient($service, $values['client_id']);
        $errors  = TaskInput::validate($values, $this->template($client));

        if ($client === null && ! isset($errors['client_id'])) {
            $errors['client_id'] = lang('Tasks.errorClient');
        }

        if ($errors === []) {
            try {
                $id = (new TaskService())->create(TaskInput::normalize($values));
                session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Tasks.saved')]);

                return redirect()->to('/tasks/' . $id);
            } catch (TaskRejected) {
                $errors['client_id'] = lang('Tasks.errorClient');
            }
        }

        return $this->response->setStatusCode(422)->setBody(view('tasks/form', $this->formData(null, $values, $errors, $clients, null)));
    }

    public function show(int $id): string
    {
        $task = (new TaskQueries())->detail($id);

        if ($task === null) {
            throw PageNotFoundException::forPageNotFound(lang('Tasks.notFound'));
        }

        return view('tasks/show', $this->showData($task, null));
    }

    public function edit(int $id): string
    {
        $task = (new TaskQueries())->detail($id);

        if ($task === null) {
            throw PageNotFoundException::forPageNotFound(lang('Tasks.notFound'));
        }

        $values = [
            'client_id' => (string) $task['client_id'],
            'url'       => (string) ($task['url'] ?? ''),
            'title'     => (string) $task['title'],
            'hours'     => (string) $task['hours'],
            'rate'      => (string) $task['rate'],
            'note'      => (string) ($task['note'] ?? ''),
        ];

        return view('tasks/form', $this->formData($id, $values, [], $this->clientsFor($task), $task));
    }

    public function update(int $id): ResponseInterface
    {
        $task = (new TaskQueries())->detail($id);

        if ($task === null) {
            throw PageNotFoundException::forPageNotFound(lang('Tasks.notFound'));
        }

        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $values  = TaskInput::fromPost($request);
        $service = new ClientService();
        $client  = $this->activeClient($service, $values['client_id']);
        $errors  = TaskInput::validate($values, $this->template($client));

        if ($client === null && ! isset($errors['client_id'])) {
            $errors['client_id'] = lang('Tasks.errorClient');
        }

        if ($errors === []) {
            try {
                (new TaskService())->update($id, TaskInput::normalize($values));
                session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Tasks.saved')]);

                return redirect()->to('/tasks/' . $id);
            } catch (TaskRejected $e) {
                $errors['client_id'] = lang($e->reason === 'client_locked' ? 'Tasks.errorClientLocked' : 'Tasks.errorClient');
            }
        }

        return $this->response->setStatusCode(422)->setBody(view('tasks/form', $this->formData($id, $values, $errors, $this->clientsFor($task), $task)));
    }

    public function delete(int $id): ResponseInterface
    {
        $queries = new TaskQueries();
        $task    = $queries->detail($id);

        if ($task === null) {
            throw PageNotFoundException::forPageNotFound(lang('Tasks.notFound'));
        }

        try {
            (new TaskService())->delete($id);
        } catch (TaskHasLockedAllocations) {
            $fresh = $queries->detail($id) ?? $task;

            return $this->response->setStatusCode(409)->setBody(view('tasks/show', $this->showData($fresh, lang('Tasks.errorHasLocked'))));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Tasks.deleted')]);

        return redirect()->to('/tasks');
    }

    public function clientDefaults(): ResponseInterface
    {
        $client = $this->activeClient(new ClientService(), (string) $this->request->getGet('client_id'));

        if ($client === null) {
            throw PageNotFoundException::forPageNotFound(lang('Tasks.errorClient'));
        }

        $data = [
            'values' => ['rate' => (string) $client['hourly_rate']],
            'errors' => [],
            'client' => $client,
            'format' => $this->numberFormat((string) $client['currency']),
        ];

        return $this->response->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view('tasks/_rate_field', $data, 'tasks/form'));
    }

    public function portalPreview(): ResponseInterface
    {
        $client = $this->activeClient(new ClientService(), (string) $this->request->getGet('client_id'));
        $url    = trim((string) $this->request->getGet('url'));
        $text   = '';

        if ($url !== '') {
            if (preg_match('/^\d{1,20}$/D', $url) === 1) {
                $template = $this->template($client);
                $text     = $template === null ? '' : PortalUrlTemplate::render($template, $url);
            } else {
                $text = $url;
            }
        }

        return $this->response->setHeader('Vary', 'HX-Request')
            ->setBody(htmx_view('tasks/_portal_preview', ['preview' => $text], 'tasks/form'));
    }

    /**
     * @param array<string, mixed>|null $client
     */
    private function template(?array $client): ?string
    {
        $tpl = $client['portal_url_template'] ?? null;

        return is_string($tpl) && $tpl !== '' ? $tpl : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeClient(ClientService $service, string $id): ?array
    {
        if (preg_match('/^\d{1,18}$/D', $id) !== 1) {
            return null;
        }

        $client = $service->find((int) $id);

        return $client === null || ($client['archived_at'] ?? null) !== null ? null : $client;
    }

    /**
     * @param list<array<string, mixed>> $clients
     *
     * @return array<string, mixed>
     */
    private function pickClient(array $clients, ?int $id): array
    {
        foreach ($clients as $client) {
            if ((int) $client['id'] === $id) {
                return $client;
            }
        }

        return $clients[0];
    }

    /**
     * Pri upravovani zostane v zozname aj aktualny (mozno archivovany) klient ulohy.
     *
     * @param array<string, mixed> $task
     *
     * @return list<array<string, mixed>>
     */
    private function clientsFor(array $task): array
    {
        $service = new ClientService();
        $clients = $service->listAll(false);

        foreach ($clients as $client) {
            if ((int) $client['id'] === (int) $task['client_id']) {
                return $clients;
            }
        }

        $own = $service->find((int) $task['client_id']);

        return $own === null ? $clients : array_merge([$own], $clients);
    }

    /**
     * @return array<string, string>
     */
    private function emptyValues(): array
    {
        return array_fill_keys(TaskInput::FIELDS, '');
    }

    /**
     * @param array<string, string>      $values
     * @param array<string, string>      $errors
     * @param list<array<string, mixed>> $clients
     * @param array<string, mixed>|null  $task
     *
     * @return array<string, mixed>
     */
    private function formData(?int $id, array $values, array $errors, array $clients, ?array $task): array
    {
        $values = array_merge($this->emptyValues(), $values);
        $client = null;

        foreach ($clients as $candidate) {
            if ((string) $candidate['id'] === ($values['client_id'] ?? '')) {
                $client = $candidate;
            }
        }

        $client ??= $clients[0] ?? null;
        $locked   = $task !== null && $task['allocations'] !== [];
        $currency = (string) ($client['currency'] ?? 'EUR');
        $format   = $this->numberFormat($currency);

        $sum = '';

        if (preg_match('/^\d{1,5}([.,]\d{1,2})?$/D', str_replace(["\u{00A0}", ' '], '', $values['hours'])) === 1
            && preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', str_replace(["\u{00A0}", ' '], '', $values['rate'])) === 1) {
            $sum = Money::of(str_replace(',', '.', str_replace(["\u{00A0}", ' '], '', $values['rate'])))
                ->timesHours(str_replace(',', '.', str_replace(["\u{00A0}", ' '], '', $values['hours'])))
                ->toDecimalString();
        }

        return [
            'title'        => lang($id === null ? 'Tasks.newTitle' : 'Tasks.editTitle'),
            'id'           => $id,
            'values'       => $values,
            'errors'       => $errors,
            'clients'      => $clients,
            'client'       => $client,
            'lockedClient' => $locked,
            'format'       => $format,
            'sum'          => $sum,
            'preview'      => $this->previewText($client, $values['url']),
        ];
    }

    /**
     * @param array<string, mixed>|null $client
     */
    private function previewText(?array $client, string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (preg_match('/^\d{1,20}$/D', $url) === 1) {
            $template = $this->template($client);

            return $template === null ? '' : PortalUrlTemplate::render($template, $url);
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return array<string, mixed>
     */
    private function showData(array $task, ?string $error): array
    {
        $locale  = service('request')->getLocale();
        $created = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Europe/Bratislava');
        $dates   = [];
        $clock   = service('clock');

        foreach (['created_at', 'done_at'] as $field) {
            $raw           = $task[$field] ?? null;
            $dates[$field] = is_string($raw) && $raw !== '' ? (string) $created->format($clock->now()->modify($raw)) : '';
        }

        return [
            'title' => (string) $task['title'],
            'task'  => $task,
            'dates' => $dates,
            'error' => $error,
        ];
    }

    /**
     * Oddelovace a symbol meny pre zivy prepocet v prehliadaci (rovnake ako MoneyFormat).
     *
     * @return array{symbol: string, decimal: string, group: string, symbolBefore: bool}
     */
    private function numberFormat(string $currency): array
    {
        $locale    = service('request')->getLocale();
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
}
