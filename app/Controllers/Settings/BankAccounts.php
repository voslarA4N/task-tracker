<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\BankAccountInput;
use App\Libraries\Master\BankAccountService;
use App\Libraries\Master\Currency;
use App\Libraries\Master\EntityService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

final class BankAccounts extends BaseController
{
    public function newForm(int $entityId): string
    {
        $entity = $this->entityOr404($entityId);
        $values = ['label' => '', 'iban' => '', 'swift' => '', 'currency' => (string) $entity['currency'], 'is_default' => false];

        return view('settings/entities/bank_account_form', $this->formData($entity, null, $values, []));
    }

    public function create(int $entityId): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $entity = $this->entityOr404($entityId);
        $values = BankAccountInput::normalize(BankAccountInput::fromPost($request, false));
        $errors = BankAccountInput::validate($values, false);

        if ($errors === []) {
            try {
                (new BankAccountService())->add($entityId, $values);
            } catch (DatabaseException $e) {
                $errors = $this->duplicateOrRethrow($e);
            }
        }

        if ($errors !== []) {
            return $this->response->setStatusCode(422)->setBody(view('settings/entities/bank_account_form', $this->formData($entity, null, $values, $errors)));
        }

        return $this->done($entityId, 'bankSaved');
    }

    public function edit(int $entityId, int $accountId): string
    {
        $entity  = $this->entityOr404($entityId);
        $account = $this->accountOr404($entityId, $accountId);
        $values  = [
            'label'      => (string) $account['label'],
            'iban'       => (string) $account['iban'],
            'swift'      => (string) ($account['swift'] ?? ''),
            'is_default' => (bool) $account['is_default'],
        ];

        return view('settings/entities/bank_account_form', $this->formData($entity, $account, $values, []));
    }

    public function update(int $entityId, int $accountId): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $entity  = $this->entityOr404($entityId);
        $account = $this->accountOr404($entityId, $accountId);
        $values  = BankAccountInput::normalize(BankAccountInput::fromPost($request, true));
        $errors  = BankAccountInput::validate($values, true);

        if ($errors === []) {
            try {
                (new BankAccountService())->update($entityId, $accountId, $values);
            } catch (DatabaseException $e) {
                $errors = $this->duplicateOrRethrow($e);
            }
        }

        if ($errors !== []) {
            return $this->response->setStatusCode(422)->setBody(view('settings/entities/bank_account_form', $this->formData($entity, $account, $values, $errors)));
        }

        return $this->done($entityId, 'bankSaved');
    }

    public function setDefault(int $entityId, int $accountId): RedirectResponse
    {
        $this->entityOr404($entityId);
        $this->accountOr404($entityId, $accountId);

        (new BankAccountService())->setDefault($entityId, $accountId);

        return $this->done($entityId, 'bankDefaultChanged');
    }

    public function archive(int $entityId, int $accountId): RedirectResponse
    {
        $this->entityOr404($entityId);
        $this->accountOr404($entityId, $accountId);

        (new BankAccountService())->archive($entityId, $accountId);

        return $this->done($entityId, 'bankArchived');
    }

    public function archived(int $entityId): RedirectResponse|ResponseInterface
    {
        $entity = $this->entityOr404($entityId);

        if (! is_htmx_fragment_request()) {
            return redirect()->to('/settings/entities/' . $entityId . '?archived=1');
        }

        return $this->response
            ->setHeader('Vary', 'HX-Request')
            ->setBody(view('settings/entities/_bank_accounts', [
                'entity'       => $entity,
                'archivedOnly' => true,
                'archivedList' => (new BankAccountService())->listFor($entityId, true),
            ]));
    }

    private function done(int $entityId, string $messageKey): RedirectResponse
    {
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Entities.' . $messageKey)]);

        return redirect()->to('/settings/entities/' . $entityId);
    }

    /**
     * @return array<string, string>
     */
    private function duplicateOrRethrow(DatabaseException $e): array
    {
        if (str_contains($e->getMessage(), 'entity_bank_accounts_active_iban')) {
            return ['iban' => lang('Entities.errorIbanDuplicate')];
        }

        throw $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function entityOr404(int $entityId): array
    {
        $entity = (new EntityService())->find($entityId);

        if ($entity === null) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        return $entity;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountOr404(int $entityId, int $accountId): array
    {
        $account = (new BankAccountService())->find($entityId, $accountId);

        if ($account === null || $account['archived_at'] !== null) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        return $account;
    }

    /**
     * @param array<string, mixed>      $entity
     * @param array<string, mixed>|null $account
     * @param array<string, mixed>      $values
     * @param array<string, string>     $errors
     *
     * @return array<string, mixed>
     */
    private function formData(array $entity, ?array $account, array $values, array $errors): array
    {
        $service = new BankAccountService();
        $first   = $account === null && $service->listFor((int) $entity['id'], false) === [];

        return [
            'title'       => lang($account === null ? 'Entities.bankNewTitle' : 'Entities.bankEditTitle'),
            'entity'      => $entity,
            'account'     => $account,
            'values'      => $values,
            'errors'      => $errors,
            'lockDefault' => $first || ($account !== null && (bool) $account['is_default']),
            'currencies'  => array_map(static fn (Currency $c): string => $c->value, Currency::cases()),
        ];
    }
}
