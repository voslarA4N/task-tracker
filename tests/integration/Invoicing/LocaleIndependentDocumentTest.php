<?php

declare(strict_types=1);

namespace Tests\Integration\Invoicing;

use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * Jazyk rozhrania nesmie zmenit vystaveny doklad (Core Value, T-12-07): HTML sablony zo snapshotu
 * aj ulozene bajty so sha256 su rovnake pri sk, pl, cs a en.
 *
 * @internal
 */
#[Group('integration')]
final class LocaleIndependentDocumentTest extends PgTestCase
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
        $this->setUiLocale('sk');
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function setUiLocale(string $locale): void
    {
        service('language')->setLocale($locale);
        service('request')->setLocale($locale);
    }

    #[DataProvider('provideIssuedDocumentIsIndependentOfInterfaceLocale')]
    public function testIssuedDocumentIsIndependentOfInterfaceLocale(string $locale): void
    {
        $s  = $this->issueSetup();
        $id = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], $s['parts']))->id;

        $snapshot = json_decode((string) $this->owner()->query('SELECT snapshot FROM invoices WHERE id = :i:', ['i' => $id])->getRow()->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $store    = new DocumentStore();

        $this->setUiLocale('sk');
        $htmlSk = view('pdf/invoice_inv2', ['s' => $snapshot, 'qr' => null]);
        $before = $store->read($id);
        $this->assertNotNull($before);
        $this->assertNotSame('', $before->sha256);

        $this->setUiLocale($locale);
        $htmlOther = view('pdf/invoice_inv2', ['s' => $snapshot, 'qr' => null]);
        $after     = $store->read($id);
        $this->assertNotNull($after);

        $this->assertSame($htmlSk, $htmlOther);
        $this->assertSame($before->bytes, $after->bytes);
        $this->assertSame($before->sha256, $after->sha256);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideIssuedDocumentIsIndependentOfInterfaceLocale(): iterable
    {
        yield 'pl' => ['pl'];

        yield 'cs' => ['cs'];

        yield 'en' => ['en'];
    }

    public function testPolishInterfaceLocaleSetsLocale(): void
    {
        service('language')->setLocale('pl');
        service('request')->setLocale('pl');

        $this->assertSame('pl', service('request')->getLocale());
    }
}
