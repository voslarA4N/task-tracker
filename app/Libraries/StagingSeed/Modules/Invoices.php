<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed\Modules;

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
            $snapshot = (new SnapshotBuilder($ctx->db))->build($client, $entity, $subset, $inv['month'], $inv['issue_date'], $inv['delivery_date'], $inv['due_date'], null);
        } catch (IssueRejected $e) {
            throw new RuntimeException('Seed invoice ' . $inv['key'] . ' rejected: ' . $e->reason, 0, $e);
        }

        return InvoiceCalculator::previewToken($snapshot);
    }
}
