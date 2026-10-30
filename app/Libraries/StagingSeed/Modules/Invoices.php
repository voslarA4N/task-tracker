<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed\Modules;

use App\Libraries\Fx\FxQuote;
use App\Libraries\Fx\FxService;
use App\Libraries\Invoicing\InvoiceCalculator;
use App\Libraries\Invoicing\InvoiceDraftService;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Invoicing\InvoiceParts;
use App\Libraries\Invoicing\IssueCommand;
use App\Libraries\Invoicing\IssueRejected;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\StagingSeed\SeedContext;
use App\Libraries\StagingSeed\SeedModule;
use RuntimeException;

/**
 * Faktury cez skutocny InvoiceIssuer (cislovanie, snapshot, PDF faktury a vykazu, QR), v chronologickom poradi
 * a so spatne datovanymi hodinami (D-03). Akekolvek odmietnutie prerusi seed s dovodom a kluciom faktury.
 */
final class Invoices implements SeedModule
{
    private const RATE = '4.2512';

    public function name(): string
    {
        return 'invoices';
    }

    public function run(SeedContext $ctx): void
    {
        $issuer = new InvoiceIssuer($ctx->db);
        $drafts = new InvoiceDraftService($ctx->db);
        $userId = $ctx->id('user', 'admin');
        $issued = [];

        foreach ($ctx->plan->invoices as $inv) {
            $ctx->clock->at($inv['at']);

            $clientId = $ctx->id('client', $inv['client']);
            $entityId = $ctx->id('entity', $inv['entity']);
            $partIds  = [];

            foreach ($inv['parts'] as $ref) {
                $partIds[] = $ctx->id('part', $ref['task'] . '#' . $ref['index']);
            }

            sort($partIds);
            $this->seedRate($ctx, $inv, $clientId, $entityId);

            if ($inv['kind'] === 'monthly') {
                $draft = $drafts->draft($clientId, $inv['month'], $entityId, $inv['issue_date'], $inv['delivery_date'], $inv['due_date']);
                $got   = $draft->partIds;
                sort($got);

                if (! $draft->canIssue || $draft->previewToken === null || $got !== $partIds) {
                    throw new RuntimeException('Seed invoice ' . $inv['key'] . ' cannot be issued as planned (canIssue=' . ($draft->canIssue ? '1' : '0') . ').');
                }

                $token = $draft->previewToken;
            } else {
                $token = $this->subsetToken($ctx, $clientId, $entityId, $inv, $partIds);
            }

            try {
                $result = $issuer->issue(new IssueCommand(
                    $clientId,
                    $inv['month'],
                    $entityId,
                    $inv['issue_date'],
                    $inv['delivery_date'],
                    $inv['due_date'],
                    $partIds,
                    $token,
                    $inv['request_key'],
                    false,
                    $userId,
                    $inv['payment_method'],
                ));
            } catch (IssueRejected $e) {
                throw new RuntimeException('Seed invoice ' . $inv['key'] . ' rejected: ' . $e->reason, 0, $e);
            }

            $ctx->put('invoice', $inv['key'], $result->id);
            $issued[] = $result->number;
        }

        $ctx->notes['invoice_numbers'] = $issued;
    }

    /**
     * Token nahladu pre podmnozinu casti (dodatocna faktura): rovnako ako nahlad, no iba nad vybranymi castami.
     *
     * @param array<string, mixed> $inv
     * @param list<int>            $partIds
     */
    private function subsetToken(SeedContext $ctx, int $clientId, int $entityId, array $inv, array $partIds): string
    {
        $client = $ctx->db->query('SELECT * FROM clients WHERE id = :c:', ['c' => $clientId])->getRowArray();
        $entity = $ctx->db->query('SELECT * FROM entities WHERE id = :e:', ['e' => $entityId])->getRowArray();

        if ($client === null || $entity === null) {
            throw new RuntimeException('Seed invoice ' . $inv['key'] . ' has no client or entity.');
        }

        $template = ($client['portal_url_template'] ?? null) === null ? null : (string) $client['portal_url_template'];
        $subset   = array_values(array_filter(
            (new InvoiceParts($ctx->db))->unlocked($clientId, $inv['month'], $template),
            static fn (array $p): bool => in_array($p['allocation_id'], $partIds, true),
        ));

        if (count($subset) !== count($partIds)) {
            throw new RuntimeException('Seed invoice ' . $inv['key'] . ' parts are not all unlocked.');
        }

        try {
            $quote    = $this->cachedQuote($ctx, $client, $entity, $inv);
            $snapshot = (new SnapshotBuilder($ctx->db))->build($client, $entity, $subset, $inv['month'], $inv['issue_date'], $inv['delivery_date'], $inv['due_date'], null, 'transfer', null, $quote);
        } catch (IssueRejected $e) {
            throw new RuntimeException('Seed invoice ' . $inv['key'] . ' rejected: ' . $e->reason, 0, $e);
        }

        return InvoiceCalculator::previewToken($snapshot);
    }

    /**
     * Faktura v inej mene nez subjekt: vloz pevny kurz NBP do cache pre bound date (D-07), aby naheld aj vystavenie
     * presli bez siete. Seed nikdy nevola API.
     *
     * @param array<string, mixed> $inv
     */
    private function seedRate(SeedContext $ctx, array $inv, int $clientId, int $entityId): void
    {
        $client = $ctx->db->query('SELECT currency FROM clients WHERE id = :c:', ['c' => $clientId])->getRowArray();
        $entity = $ctx->db->query('SELECT country, currency FROM entities WHERE id = :e:', ['e' => $entityId])->getRowArray();

        if ($client === null || $entity === null) {
            return;
        }

        $fx = $this->fx($ctx)->context((string) $entity['country'], trim((string) $entity['currency']), trim((string) $client['currency']), $inv['issue_date'], $inv['delivery_date']);

        if ($fx === null || ! $fx->supported) {
            return;
        }

        $ctx->db->query(
            'INSERT INTO exchange_rate_cache (source, currency, bound_date, valid_for, rate, rate_published, amount, table_no, direction, fetched_at)
             VALUES (:s:, :c:, :b:, :b:, :r:, :r:, 1, :t:, :d:, :f:) ON CONFLICT (source, currency, bound_date) DO NOTHING',
            [
                's' => $fx->source,
                'c' => $fx->invoiceCurrency,
                'b' => $fx->bound,
                'r' => self::RATE,
                't' => '209/A/NBP/2026',
                'd' => $fx->direction,
                'f' => service('clock')->now()->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $inv
     */
    private function cachedQuote(SeedContext $ctx, array $client, array $entity, array $inv): ?FxQuote
    {
        $service = $this->fx($ctx);
        $fx      = $service->context((string) $entity['country'], trim((string) $entity['currency']), trim((string) $client['currency']), $inv['issue_date'], $inv['delivery_date']);

        return $fx === null ? null : $service->cached($fx);
    }

    private function fx(SeedContext $ctx): FxService
    {
        return new FxService($ctx->db);
    }
}
