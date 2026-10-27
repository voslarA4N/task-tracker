<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\InvoiceCalculator;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Invoicing\InvoiceParts;
use App\Libraries\Invoicing\IssueCommand;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Master\BankAccountService;

/**
 * Fixtures vystavenia faktury (Phase 5). Pouziva MasterFixtures.
 *
 * @mixin PgTestCase
 */
trait IssueFixtures
{
    /**
     * @param array<string, mixed> $opts country, format, initial, client, parts (list of [hours, month] alebo [hours, month, taskOverrides])
     *
     * @return array{entity: int, client: int, tasks: list<int>, parts: list<int>, series: int}
     */
    protected function issueSetup(array $opts = []): array
    {
        $country  = (string) ($opts['country'] ?? 'SK');
        $currency = ['PL' => 'PLN', 'CZ' => 'CZK'][$country] ?? 'EUR';
        $iban     = ['PL' => 'PL61109010140000071219812874', 'CZ' => 'CZ6508000000192000145399'][$country] ?? 'SK3112000000198742637541';
        $entityId = $this->createEntity($country, $country === 'CZ' ? ['currency' => 'CZK', 'vat_regime' => 'cz_non_vat', 'doc_language' => 'cs'] : []);

        (new BankAccountService($this->rw(), new AuditLogger($this->rw())))->add($entityId, [
            'label'      => 'Hlavny ucet',
            'iban'       => $iban,
            'swift'      => $country === 'PL' ? 'WBKPPLPP' : 'GIBASKBX',
            'currency'   => $currency,
            'is_default' => true,
        ]);

        $seriesId = (int) $this->owner()->query("SELECT id FROM invoice_series WHERE entity_id = :e: AND kind = 'invoice'", ['e' => $entityId])->getRow()->id;
        $this->owner()->query(
            'UPDATE invoice_series SET format = :f:, initial_number = :i: WHERE id = :id:',
            ['f' => $opts['format'] ?? 'RRRRMM###', 'i' => (int) ($opts['initial'] ?? 1), 'id' => $seriesId],
        );

        $clientOverrides = ($opts['client'] ?? []) + ['currency' => $currency, 'hourly_rate' => '27.00'];

        if (in_array($country, ['PL', 'CZ'], true) && ! isset($opts['client']['country'])) {
            $clientOverrides['country'] = $country;
        }

        $clientId = $this->createClient($clientOverrides);
        $tasks    = [];
        $parts    = [];

        foreach ($opts['parts'] ?? [['40.00', '2026-10']] as $spec) {
            $taskId  = $this->createTask($clientId, $spec[2] ?? []);
            $tasks[] = $taskId;
            $parts[] = $this->createAllocation($taskId, (string) $spec[0], (string) $spec[1]);
        }

        return ['entity' => $entityId, 'client' => $clientId, 'tasks' => $tasks, 'parts' => $parts, 'series' => $seriesId];
    }

    /**
     * @param list<int>            $partIds
     * @param array<string, mixed> $overrides tvarove polia IssueCommand (issueDate, deliveryDate, dueDate, previewToken, requestKey, confirmBackdate, userId)
     */
    protected function issueCommand(int $clientId, string $month, int $entityId, array $partIds, array $overrides = []): IssueCommand
    {
        sort($partIds);

        $issueDate    = (string) ($overrides['issueDate'] ?? '2026-10-27');
        $deliveryDate = (string) ($overrides['deliveryDate'] ?? '2026-10-31');
        $dueDate      = (string) ($overrides['dueDate'] ?? '2026-11-10');
        $token        = $overrides['previewToken'] ?? $this->previewTokenFor($clientId, $month, $entityId, $partIds, $issueDate, $deliveryDate, $dueDate);

        return new IssueCommand(
            $clientId,
            $month,
            $entityId,
            $issueDate,
            $deliveryDate,
            $dueDate,
            $partIds,
            (string) $token,
            (string) ($overrides['requestKey'] ?? $this->uuid4()),
            (bool) ($overrides['confirmBackdate'] ?? false),
            $overrides['userId'] ?? null,
        );
    }

    protected function issuer(?PdfRenderer $renderer = null): InvoiceIssuer
    {
        return new InvoiceIssuer($this->rw(), null, $renderer ?? new FakePdfRenderer());
    }

    protected function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h    = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /**
     * @param list<int> $partIds
     */
    protected function previewTokenFor(int $clientId, string $month, int $entityId, array $partIds, string $issueDate = '2026-10-27', string $deliveryDate = '2026-10-31', string $dueDate = '2026-11-10'): string
    {
        $client = $this->rw()->query('SELECT * FROM clients WHERE id = :c:', ['c' => $clientId])->getRowArray();
        $entity = $this->rw()->query('SELECT * FROM entities WHERE id = :e:', ['e' => $entityId])->getRowArray();

        if ($client === null || $entity === null) {
            return str_repeat('0', 64);
        }

        $template = ($client['portal_url_template'] ?? null) === null ? null : (string) $client['portal_url_template'];
        $unlocked = array_values(array_filter(
            (new InvoiceParts($this->rw()))->unlocked($clientId, $month, $template),
            static fn (array $p): bool => in_array($p['allocation_id'], $partIds, true),
        ));

        if ($unlocked === []) {
            return str_repeat('0', 64);
        }

        try {
            $snapshot = (new SnapshotBuilder($this->rw()))->build($client, $entity, $unlocked, $month, $issueDate, $deliveryDate, $dueDate, null);
        } catch (IssueRejected) {
            return str_repeat('0', 64);
        }

        return InvoiceCalculator::previewToken($snapshot);
    }
}
