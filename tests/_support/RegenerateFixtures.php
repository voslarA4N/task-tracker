<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Invoicing\DocumentRegenerator;
use App\Libraries\Invoicing\PdfRenderer;
use App\Libraries\Invoicing\RendererRegistry;

/**
 * Fixtures pregenerovania dokladov (Phase 11.1). Vyzaduje InvoiceFixtures.
 *
 * @mixin PgTestCase
 */
trait RegenerateFixtures
{
    /**
     * @param list<int> $allocationIds
     */
    protected function insertLegacyRep1Invoice(int $clientId, int $entityId, string $month, array $allocationIds): int
    {
        return $this->insertIssuedInvoiceRaw($clientId, $entityId, $month, $allocationIds, '%PDF-1.4 test', [
            'snapshot_schema' => 2,
            'with_report'     => true,
            'snapshot'        => json_encode(SnapshotSample::issuedV2(['report_template_version' => 'rep-1']), JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, PdfRenderer> $renderers
     */
    protected function regenerator(array $renderers = []): DocumentRegenerator
    {
        $renderers += [
            'inv-2' => new FakePdfRenderer('inv-2'),
            'rep-2' => new FakePdfRenderer('rep-2'),
            'cn-1'  => new FakePdfRenderer('cn-1'),
        ];

        return new DocumentRegenerator($this->rw(), null, new RendererRegistry($renderers));
    }
}
