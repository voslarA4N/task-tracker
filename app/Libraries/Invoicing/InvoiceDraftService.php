<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Invoicing\Qr\PolishZbpQr;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\LegalProfileResolver;
use App\Libraries\Master\LegalTextMissing;
use App\Libraries\Master\SeriesFormat;
use App\Libraries\Master\VersionNotFound;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Nahlad "Fakturacia mesiaca": iba citanie, bez transakcie a bez zamkov. Pouziva rovnaky SnapshotBuilder
 * a InvoiceCalculator ako vystavenie (D-02), takze nahlad a faktura davaju rovnake riadky a sucet.
 */
final class InvoiceDraftService
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly InvoiceParts $parts;
    private readonly InvoiceGuards $guards;
    private readonly SnapshotBuilder $builder;
    private readonly EntityDirectory $entities;
    private readonly EntityVersionResolver $versions;
    private readonly LegalProfileResolver $legal;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->parts    = new InvoiceParts($this->db);
        $this->guards   = new InvoiceGuards($this->db);
        $this->builder  = new SnapshotBuilder($this->db);
        $this->entities = new EntityDirectory($this->db);
        $this->versions = new EntityVersionResolver($this->db);
        $this->legal    = new LegalProfileResolver($this->db);
    }

    /**
     * Najstarsi mesiac s nezamknutymi castami klienta, inak predchadzajuci mesiac.
     */
    public function defaultMonth(int $clientId): string
    {
        $row = $this->db->query(
            'SELECT min(a.billing_month) AS m FROM billing_allocations a JOIN tasks t ON t.id = a.task_id
             WHERE t.client_id = :c: AND a.invoice_id IS NULL',
            ['c' => $clientId],
        )->getRowArray();

        if ($row !== null && $row['m'] !== null) {
            return (string) $row['m'];
        }

        return BillingMonth::previous(BillingMonth::current());
    }

    public function draft(int $clientId, string $month, ?int $entityId, ?string $issueDate, ?string $deliveryDate, ?string $dueDate, ?string $paymentMethod = null, ?string $paymentNote = null, bool $noteDirty = false): Draft
    {
        $clock = service('clock');

        if (BillingMonth::parse($month) === null) {
            throw new InvalidArgumentException('Invalid billing month.');
        }

        $client = $this->db->query('SELECT * FROM clients WHERE id = :c:', ['c' => $clientId])->getRowArray();

        if ($client === null) {
            throw new InvalidArgumentException('Client not found.');
        }

        $issue    = $this->validDate($issueDate) ?? $clock->today();
        $delivery = $this->validDate($deliveryDate) ?? $this->lastDayOfMonth($month);
        $entities = $this->entities->activeAt($issue);
        $errors   = [];

        $selected = null;

        foreach ($entities as $e) {
            if ($entityId !== null && $e['id'] === $entityId) {
                $selected = $e['id'];
            }
        }

        $selected ??= $entities[0]['id'] ?? null;

        $entity = null;

        if ($selected === null) {
            $errors[] = ['reason' => 'entity_inactive', 'context' => ['date' => $issue]];
        } else {
            $entity = $this->db->query('SELECT * FROM entities WHERE id = :e:', ['e' => $selected])->getRowArray();
        }

        $due = $this->validDate($dueDate) ?? $this->defaultDue($client, $selected, $issue);

        $template = ($client['portal_url_template'] ?? null) === null ? null : (string) $client['portal_url_template'];
        $parts    = $this->parts->unlocked($clientId, $month, $template);
        $locked   = $this->parts->hasLocked($clientId, $month);

        $lines    = [];
        $total    = '0.00';
        $hours    = '0.00';
        $count    = 0;
        $currency = null;
        $token    = null;
        $snapshot = null;

        if ($parts !== []) {
            try {
                $calc     = (new InvoiceCalculator())->compute($parts, $this->vatCode($client, $entity, $issue));
                $lines    = $calc['lines'];
                $total    = $calc['total'];
                $hours    = $calc['hours'];
                $count    = $calc['line_count'];
                $currency = $calc['currency'];

                if ($entity !== null) {
                    // mena sa kontroluje pred vyberom bankoveho uctu, inak by sa ukazal zavadzajuci no_bank_account
                    $this->guards->assertCurrency($entity, $this->guards->partsCurrency($parts));
                    $snapshot = $this->builder->build($client, $entity, $parts, $month, $issue, $delivery, $due, null);
                    $token    = InvoiceCalculator::previewToken($snapshot);
                }
            } catch (IssueRejected $e) {
                $errors[] = ['reason' => $e->reason, 'context' => $e->context];
            }
        }

        $backdate  = null;
        $seriesFmt = null;

        if ($selected !== null) {
            $series = $this->db->query("SELECT id, format FROM invoice_series WHERE entity_id = :e: AND kind = 'invoice'", ['e' => $selected])->getRowArray();

            if ($series !== null) {
                $backdate  = $this->guards->backdate((int) $series['id'], $issue);
                $seriesFmt = (string) $series['format'];
            }
        }

        $method      = $paymentMethod !== null && in_array($paymentMethod, IssueCommand::PAYMENT_METHODS, true) ? $paymentMethod : 'transfer';
        $texts       = is_array($snapshot) ? ($snapshot['legal']['texts'] ?? []) : [];
        $defaultNote = is_array($texts['invoice_note'] ?? null) ? $texts['invoice_note'] : null;
        $note        = $noteDirty ? IssueInput::normalizeNote((string) $paymentNote) : ($defaultNote['body'] ?? null);
        $provisional = (bool) ($texts['vat_note']['provisional'] ?? false) || (! $noteDirty && (bool) ($defaultNote['provisional'] ?? false));

        $zbpApplies       = is_array($snapshot) && $method === 'transfer' && PolishZbpQr::applies($snapshot);
        $zbpWarning       = $zbpApplies && $seriesFmt !== null && ! PolishZbpQr::titleFits(self::longestNumber($seriesFmt, $issue));
        $zbpAmountWarning = $zbpApplies && ! PolishZbpQr::amountFits((string) ($snapshot['totals']['total'] ?? ''));

        return new Draft(
            $clientId,
            $month,
            $selected,
            $issue,
            $delivery,
            $due,
            $lines,
            $total,
            $hours,
            $count,
            $currency,
            array_map(static fn (array $l): int => (int) $l['source_allocation_id'], $lines),
            $token,
            $snapshot,
            $backdate,
            $locked,
            $errors,
            $entities,
            $count > 0 && $errors === [] && $selected !== null && $token !== null,
            $method,
            $note,
            $noteDirty,
            $provisional,
            $zbpWarning,
            $zbpAmountWarning,
        );
    }

    /**
     * Najdlhsie mozne cislo radu (len citanie formatu). Neplatny format sa ignoruje, vystavenie ho odmietne.
     */
    private static function longestNumber(string $format, string $issueDate): string
    {
        try {
            $parsed = SeriesFormat::parse($format);

            return $parsed->render($issueDate, $parsed->maxSequence());
        } catch (InvalidSeriesFormat) {
            return '';
        }
    }

    private function validDate(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return service('clock')->parseDate($iso)?->format('Y-m-d');
    }

    private function lastDayOfMonth(string $month): string
    {
        $first = service('clock')->parseDate($month . '-01');

        return $first instanceof DateTimeImmutable ? $first->modify('last day of this month')->format('Y-m-d') : $month . '-28';
    }

    /**
     * @param array<string, mixed> $client
     */
    private function defaultDue(array $client, ?int $entityId, string $issue): string
    {
        $days = $client['due_days'] === null ? null : (int) $client['due_days'];

        if ($days === null && $entityId !== null) {
            try {
                $days = (int) $this->versions->atDate($entityId, $issue)['due_days'];
            } catch (VersionNotFound) {
                $days = null;
            }
        }

        $base = service('clock')->parseDate($issue);

        return $base instanceof DateTimeImmutable ? $base->modify('+' . ($days ?? 0) . ' days')->format('Y-m-d') : $issue;
    }

    /**
     * @param array<string, mixed>      $client
     * @param array<string, mixed>|null $entity
     */
    private function vatCode(array $client, ?array $entity, string $issue): ?string
    {
        if ($entity === null) {
            return null;
        }

        try {
            $version = $this->versions->atDate((int) $entity['id'], $issue);

            return $this->legal->profile((string) $entity['country'], (string) $version['vat_regime'], (string) $client['billing_mode'])['vat_code'];
        } catch (LegalTextMissing|VersionNotFound) {
            return null;
        }
    }
}
