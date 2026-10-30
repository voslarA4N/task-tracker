<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\Samples\SampleSnapshots;
use App\Libraries\Master\LegalProfileResolver;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class SampleSnapshotShapeTest extends PgTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * Mnozina ciest kluca; zoznamy sa zredukuju na prvy prvok, volne mapy (labels, texts) na uroven kluca.
     *
     * @param array<int|string, mixed> $a
     *
     * @return list<string>
     */
    private function paths(array $a, string $prefix = ''): array
    {
        $out = [];

        foreach ($a as $k => $v) {
            if (is_int($k) && $k > 0) {
                continue;
            }

            $p     = $prefix . ($prefix === '' ? '' : '.') . (is_int($k) ? '*' : $k);
            $out[] = $p;

            if (is_array($v) && ! in_array($k, ['labels'], true)) {
                array_push($out, ...$this->paths($v, $p));
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function issued(string $country): array
    {
        $s = $this->issueSetup(['country' => $country]);

        if ($country === 'PL') {
            $this->owner()->query("UPDATE entity_versions SET tax_id = '1234563218' WHERE entity_id = :e:", ['e' => $s['entity']]);
        }

        $id = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;
        $r  = $this->owner()->query('SELECT snapshot FROM invoices WHERE id = :id:', ['id' => $id])->getRow();

        return json_decode((string) $r->snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $sample
     * @param array<string, mixed> $issued
     */
    private function assertSameShape(array $sample, array $issued): void
    {
        unset($sample['sample']);

        $this->assertSame($this->paths($issued), $this->paths($sample));
    }

    public function testSkSeedAndResolverShapeMatchIssued(): void
    {
        $issued = $this->issued('SK');

        $this->assertSameShape(SampleSnapshots::invoice('sk'), $issued);
        $this->assertSameShape(SampleSnapshots::invoice('sk', null, [], new LegalProfileResolver($this->rw())), $issued);
    }

    public function testPlShapeMatchesIssued(): void
    {
        $issued = $this->issued('PL');

        $this->assertSameShape(SampleSnapshots::invoice('pl'), $issued);
        $this->assertSameShape(SampleSnapshots::invoice('pl', null, [], new LegalProfileResolver($this->rw())), $issued);
    }

    public function testFxSampleKeepsIssuedShapeWithFxBlock(): void
    {
        $issued = $this->issued('PL');

        foreach ([false, true] as $manual) {
            $sample = SampleSnapshots::fxInvoice($manual);
            $fx     = $sample['fx'];
            unset($sample['fx'], $issued['fx'], $sample['rounding']['fx']);

            $this->assertSameShape($sample, $issued);
            $this->assertSame($manual, $fx['manual']);
            $this->assertSame(3, SampleSnapshots::fxInvoice($manual)['schema']);
        }
    }

    public function testSeedTextMatchesResolverContract(): void
    {
        $seed     = SampleSnapshots::invoice('sk')['legal']['texts']['vat_note'];
        $resolved = ['key' => 'vat_note'] + (new LegalProfileResolver($this->rw()))->text('SK', 'sk_non_vat', 'domestic', 'sk', 'vat_note');

        $this->assertSame(1, $seed['revision']);
        $this->assertArrayHasKey('billing_mode', $seed);
        $this->assertSame(array_keys($resolved), array_keys($seed));
    }

    public function testSampleFlagOnlyOnSamples(): void
    {
        $this->assertTrue(SampleSnapshots::invoice('sk')['sample']);
        $this->assertArrayNotHasKey('sample', $this->issued('SK'));
    }
}
