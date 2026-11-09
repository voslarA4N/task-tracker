<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * HTTP-level proof (D-01/D-02): dashboard overdue list, payment header and delivery
 * header show the snapshot client name after the live client is renamed.
 *
 * @internal
 */
#[Group('feature')]
final class SnapshotClientNameHeadersTest extends AuthTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    private const RENAMED = 'Premenovany s.r.o.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useClock('2026-10-27T10:00:00+01:00');
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function useClock(string $at): void
    {
        Services::injectMock('clock', new FrozenClock($at));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    private function login(): void
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function overdueSection(string $html): string
    {
        $start = strpos($html, 'id="overdue-title"');
        $this->assertNotFalse($start, 'overdue section missing');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    /**
     * Issues a real invoice, then renames the live client. Returns invoice id and the snapshot name.
     *
     * @return array{id: int, snapshot: string, client: int, entity: int}
     */
    private function issuedThenRenamed(): array
    {
        $inv      = $this->issuedInvoice();
        $snapshot = (string) $this->owner()->query("SELECT snapshot->'client'->>'name' AS n FROM invoices WHERE id = :i:", ['i' => $inv['id']])->getRow()->n;
        $this->assertNotSame('', $snapshot);
        $this->assertNotSame(self::RENAMED, $snapshot);
        $this->owner()->query('UPDATE clients SET name = :n: WHERE id = :id:', ['n' => self::RENAMED, 'id' => (int) $inv['client']]);

        return ['id' => $inv['id'], 'snapshot' => $snapshot, 'client' => (int) $inv['client'], 'entity' => (int) $inv['entity']];
    }

    public function testDashboardOverdueShowsSnapshotName(): void
    {
        $this->login();
        $inv = $this->issuedThenRenamed();
        $this->useClock('2027-01-15T10:00:00+01:00');

        $html = $this->overdueSection($this->body($this->get('/')));

        $this->assertStringContainsString(esc($inv['snapshot']), $html);
        $this->assertStringNotContainsString(self::RENAMED, $html);
    }

    public function testDashboardOverdueFallsBackToLiveNameWithoutSnapshotName(): void
    {
        $this->login();
        $s      = $this->issueSetup();
        $client = (int) $s['client'];
        $this->owner()->query('UPDATE clients SET name = :n: WHERE id = :id:', ['n' => self::RENAMED, 'id' => $client]);
        $this->insertIssuedInvoiceRaw($client, (int) $s['entity'], '2026-09', $s['parts'], '%PDF-1.4 test', ['due_date' => '2026-10-01', 'number' => '202609001']);
        $this->useClock('2026-10-27T10:00:00+01:00');

        $html = $this->overdueSection($this->body($this->get('/')));

        $this->assertStringContainsString(self::RENAMED, $html);
    }

    public function testPaymentHeaderShowsSnapshotNameFullPageAndSheet(): void
    {
        $this->login();
        $inv = $this->issuedThenRenamed();

        foreach ([[], ['HX-Request' => 'true']] as $headers) {
            $html = $this->body($this->withHeaders($headers)->get('/invoices/' . $inv['id'] . '/payments'));

            $this->assertStringContainsString(esc($inv['snapshot']), $html);
            $this->assertStringNotContainsString(self::RENAMED, $html);
        }
    }

    public function testDeliveryHeaderShowsSnapshotNameAndRecipientStaysLive(): void
    {
        $this->login();
        $inv = $this->issuedThenRenamed();
        $this->owner()->query('UPDATE clients SET invoice_email = :e: WHERE id = :id:', ['e' => 'nova@live.example', 'id' => $inv['client']]);

        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $inv['id'] . '/send'));

        $this->assertStringContainsString(esc($inv['snapshot']), $html);
        $this->assertStringNotContainsString(self::RENAMED, $html);
        $this->assertStringContainsString('nova&#x40;live.example', $html);
    }
}
