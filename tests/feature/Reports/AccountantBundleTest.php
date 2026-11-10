<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Libraries\Export\AccountantBundle;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
use Closure;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\ReportFixtures;
use Tests\Support\TurnoverFixtures;
use ZipArchive;

/**
 * Obrazovka Balik pre uctovnicku (RPT-03, D-09 az D-12).
 *
 * @internal
 */
#[Group('feature')]
final class AccountantBundleTest extends AuthTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;
    use ReportFixtures;
    use TurnoverFixtures;

    private int $entity = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetMasterTables();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function login(string $group = 'admin'): void
    {
        $user = $this->createUser('owner@example.test', $group);
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function seed(): void
    {
        $this->entity = $this->createEntity('SK', ['active_from' => '2025-01-01']);
        $client       = $this->createClient(['name' => 'Klient A']);
        $this->reportInvoice($this->entity, $client, '2026-10-10', '1000.00');
        $b = $this->reportInvoice($this->entity, $client, '2026-10-12', '200.00');
        $this->turnoverCreditNote($b, '2026-10-20');
    }

    private function failingZip(string $mode): Closure
    {
        return static fn (): ZipArchive => new class ($mode) extends ZipArchive {
            public function __construct(private readonly string $mode)
            {
            }

            public function close(): bool
            {
                parent::close();

                if ($this->mode === 'throw') {
                    throw new ErrorException('zip close failed');
                }

                return false;
            }
        };
    }

    #[DataProvider('provideDownloadBuildFailureShowsErrorBuild')]
    public function testDownloadBuildFailureShowsErrorBuild(string $mode): void
    {
        $this->seed();
        $this->login();
        Services::injectMock('accountantBundle', new AccountantBundle(null, $this->failingZip($mode)));
        $before = glob(WRITEPATH . 'exports/*') ?: [];

        $result = $this->get('/reports/accountant/download?entity=' . $this->entity . '&from=2026-10&to=2026-10');

        $this->assertSame(500, $result->response()->getStatusCode());
        $this->assertStringContainsString(esc(lang('Bundle.errorBuild')), $this->body($result));
        $this->assertStringNotContainsString('application/zip', $result->response()->getHeaderLine('Content-Type'));
        $this->assertSame($before, glob(WRITEPATH . 'exports/*') ?: []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideDownloadBuildFailureShowsErrorBuild(): iterable
    {
        return ['ErrorException' => ['throw'], 'RuntimeException' => ['false']];
    }

    public function testFormDefaultsToPreviousMonth(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->get('/reports/accountant'));

        $this->assertMatchesRegularExpression('/name="from" value="2026-10"/', $body);
        $this->assertMatchesRegularExpression('/name="to" value="2026-10"/', $body);
        $this->assertStringContainsString('name="entity"', $body);
        $this->assertStringContainsString('id="bundle-preview"', $body);
    }

    public function testPreviewShowsCountsAndTurnover(): void
    {
        $this->seed();
        $this->login();
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/reports/accountant/preview?entity=' . $this->entity . '&from=2026-10&to=2026-10');
        $result->assertStatus(200);
        $body = $this->body($result);

        $this->assertStringStartsWith('<div id="bundle-preview">', trim($body));
        $this->assertStringContainsString('3 dokladov: 2 faktúr, 1 dobropisov', $body);
        $this->assertStringContainsString('z toho 1 stornovaných', $body);
        $this->assertStringContainsString(MoneyFormat::format('1000.00', 'EUR', 'sk'), $body);
        $this->assertStringNotContainsString(' disabled', $body);
    }

    public function testInvalidRangeIs422(): void
    {
        $this->seed();
        $this->login();
        $h = ['HX-Request' => 'true'];

        $order = $this->withHeaders($h)->get('/reports/accountant/preview?entity=' . $this->entity . '&from=2026-11&to=2026-10');
        $order->assertStatus(422);
        $this->assertStringContainsString('nesmie byť neskôr', $this->body($order));

        $long = $this->withHeaders($h)->get('/reports/accountant/preview?entity=' . $this->entity . '&from=2025-10&to=2026-10');
        $long->assertStatus(422);
        $this->assertStringContainsString('najviac 12 mesiacov', $this->body($long));
    }

    public function testOutOfRangeIs422(): void
    {
        $this->seed();
        $this->login();

        $preview = $this->withHeaders(['HX-Request' => 'true'])->get('/reports/accountant/preview?entity=' . $this->entity . '&from=9999-12&to=9999-12');
        $preview->assertStatus(422);
        $this->assertStringContainsString('Obdobie musí byť v rozmedzí január 2000 až december 2100', $this->body($preview));

        $download = $this->get('/reports/accountant/download?entity=' . $this->entity . '&from=9999-12&to=9999-12');
        $download->assertStatus(422);
    }

    public function testEmptyPeriodDisablesButton(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/reports/accountant/preview?entity=' . $this->entity . '&from=2025-01&to=2025-01'));

        $this->assertStringContainsString('nie sú žiadne doklady', $body);
        $this->assertStringContainsString(' disabled', $body);
    }

    public function testDownloadZip(): void
    {
        $this->seed();
        $this->login();
        $result = $this->get('/reports/accountant/download?entity=' . $this->entity . '&from=2026-10&to=2026-10');
        $result->assertStatus(200);
        $res = $result->response();

        $this->assertSame('application/zip', $res->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="balik-moja-firma-sk-2026-10-2026-10.zip"', $res->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('PK', (string) $res->getBody());
    }

    public function testDownloadWithMissingPdfIs422(): void
    {
        $this->seed();
        $id = $this->reportInvoice($this->entity, $this->createClient(['name' => 'Klient Z', 'identifier_value' => '33333333']), '2026-10-15', '50.00', ['number' => 'NOPDF9']);
        $db = $this->owner();
        $db->query('BEGIN');
        $db->query('ALTER TABLE invoice_documents DISABLE TRIGGER invoice_documents_immutable');
        $db->query("DELETE FROM invoice_documents WHERE invoice_id = :i: AND kind = 'invoice'", ['i' => $id]);
        $db->query('ALTER TABLE invoice_documents ENABLE TRIGGER invoice_documents_immutable');
        $db->query('COMMIT');

        $this->login();
        $result = $this->get('/reports/accountant/download?entity=' . $this->entity . '&from=2026-10&to=2026-10');
        $result->assertStatus(422);
        $this->assertStringContainsString('NOPDF9', $this->body($result));
    }

    public function testAccountantIsForbidden(): void
    {
        $this->seed();
        $this->login('accountant');
        $this->get('/reports/accountant')->assertStatus(403);
    }

    public function testMoreMenuLinksBundleBeforeEntities(): void
    {
        $this->seed();
        $this->login();
        $body = $this->body($this->get('/more'));

        $a = strpos($body, 'href="/reports/accountant"');
        $this->assertNotFalse($a);
        $this->assertLessThan((int) strpos($body, 'href="/settings/entities"'), $a);
    }

    public function testDownloadWithRegeneratedVersionHasOneRowAndOnePdf(): void
    {
        $this->seed();
        $id    = (int) $this->owner()->query("SELECT id FROM invoices WHERE type = 'invoice' ORDER BY id LIMIT 1")->getRow()->id;
        $bytes = '%PDF-1.4 regenerated';
        $this->owner()->query(
            "INSERT INTO invoice_documents (invoice_id, kind, version, content, byte_size, sha256, template_version, renderer, created_at)
             VALUES (:i:, 'invoice', 2, decode(:hex:, 'hex'), :size:, :sha:, 'test-2', 'test', now())",
            ['i' => $id, 'hex' => bin2hex($bytes), 'size' => strlen($bytes), 'sha' => hash('sha256', $bytes)],
        );

        $this->login();
        $result = $this->get('/reports/accountant/download?entity=' . $this->entity . '&from=2026-10&to=2026-10');
        $result->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'bz');
        file_put_contents($path, (string) $result->response()->getBody());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        try {
            $this->assertSame(4, $zip->numFiles);
            $csv = (string) $zip->getFromName('zoznam-dokladov.csv');
            $this->assertCount(4, array_values(array_filter(explode("\n", trim($csv)))));
            $found = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $found += $zip->getFromIndex($i) === $bytes ? 1 : 0;
            }

            $this->assertSame(1, $found);
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
