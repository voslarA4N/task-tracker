<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class MarkSentTest extends AuthTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
        $this->resetMasterTables();
    }

    protected function tearDown(): void
    {
        $this->resetMail();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
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

    private function rows(string $table): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS n FROM ' . $table)->getRow()->n;
    }

    /**
     * @param array<string, string> $data
     */
    private function mark(int $id, array $data = [], bool $csrf = true): TestResponse
    {
        $this->withSession();
        $data += ['date' => '2026-10-27', 'note' => 'Odovzdané osobne', 'request_key' => $this->uuid4()];

        return $this->withHeaders(['HX-Request' => 'true'])->call('post', '/invoices/' . $id . '/mark-sent', $data + ($csrf ? [csrf_token() => csrf_hash()] : []));
    }

    /**
     * @param array<string, string> $data
     */
    private function void(int $sendId, array $data): TestResponse
    {
        $this->withSession();

        return $this->withHeaders(['HX-Request' => 'true'])->call('post', '/sends/' . $sendId . '/void', $data + [csrf_token() => csrf_hash()]);
    }

    private function sendId(): int
    {
        return (int) $this->owner()->query('SELECT id FROM invoice_sends ORDER BY id DESC LIMIT 1')->getRow()->id;
    }

    private function assertNoInline(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|onclick|<script(?![^>]*\bsrc=)/i', $html);
    }

    public function testDetailOfIssuedInvoiceHasMarkButtonAndSheet(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $detail = $this->body($this->get('/invoices/' . $inv['id']));
        $this->assertStringContainsString('/invoices/' . $inv['id'] . '/mark-sent', $detail);
        $this->assertStringContainsString('Označiť ako odoslanú', $detail);

        $page = $this->body($this->get('/invoices/' . $inv['id'] . '/mark-sent'));
        $this->assertStringContainsString('<html', $page);
        $this->assertNoInline($page);

        $sheet = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/invoices/' . $inv['id'] . '/mark-sent'));
        $this->assertStringContainsString('id="work-sheet"', $sheet);
        $this->assertStringContainsString('value="2026-10-27"', $sheet);
        $this->assertStringContainsString('max="2026-10-27"', $sheet);
        $this->assertStringContainsString('maxlength="200"', $sheet);
        $this->assertStringContainsString('data-submit-once', $sheet);
        $this->assertNoInline($sheet);
    }

    public function testPostMarksSentAndShowsInHistory(): void
    {
        $this->login();
        $inv    = $this->issuedInvoice();
        $result = $this->mark($inv['id']);

        $result->assertStatus(200);
        $this->assertSame('/invoices/' . $inv['id'], $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame('Faktúra je označená ako odoslaná.', session()->getFlashdata('app_notice')['message'] ?? '');
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS n FROM invoice_sends WHERE kind <> 'manual'")->getRow()->n);

        $html = $this->body($this->get('/invoices/' . $inv['id']));
        $this->assertStringContainsString('Odoslaná', $html);
        $this->assertStringContainsString('Ručne označená ako odoslaná', $html);
        $this->assertStringContainsString('Odovzdané osobne', $html);
        $this->assertStringContainsString('Zrušiť označenie', $html);
        $this->assertStringNotContainsString('/mark-sent', $html);
        $this->assertNoInline($html);
    }

    public function testFutureDateIs422AndPostWithoutCsrfIs403(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();

        $future = $this->mark($inv['id'], ['date' => '2026-10-28']);
        $future->assertStatus(422);
        $this->assertStringContainsString('Dátum odoslania nemôže byť v budúcnosti.', $this->body($future));
        $this->mark($inv['id'], ['date' => 'nie']);
        $this->assertSame(0, $this->rows('invoice_sends'));

        $this->mark($inv['id'], [], false)->assertStatus(403);
        $this->assertSame(0, $this->rows('invoice_sends'));
    }

    public function testVoidNeedsReasonAndRestoresIssued(): void
    {
        $this->login();
        $inv = $this->issuedInvoice();
        $this->mark($inv['id'], ['note' => '<b>x</b>']);
        $sid = $this->sendId();

        $form = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/sends/' . $sid . '/void'));
        $this->assertStringContainsString('btn-danger', $form);
        $this->assertNoInline($form);

        $empty = $this->void($sid, ['reason' => '']);
        $empty->assertStatus(422);
        $this->assertStringContainsString('Zadajte dôvod storna.', $this->body($empty));
        $this->assertSame(0, $this->rows('invoice_send_voids'));

        $ok = $this->void($sid, ['reason' => 'Omyl pri označení']);
        $ok->assertStatus(200);
        $this->assertSame('/invoices/' . $inv['id'], $ok->response()->getHeaderLine('HX-Location'));
        $this->assertSame(1, $this->rows('invoice_send_voids'));
        $this->assertSame(1, $this->rows('invoice_sends'));

        $html = $this->body($this->get('/invoices/' . $inv['id']));
        $this->assertStringContainsString('text-decoration-line-through', $html);
        $this->assertStringContainsString('Omyl pri označení', $html);
        $this->assertStringContainsString('Zrušené', $html);
        $this->assertStringContainsString('Vystavená', $html);
        $this->assertStringNotContainsString('&lt;b&gt;x&lt;/b&gt;<script', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);

        $this->void($sid, ['reason' => 'Znova'])->assertStatus(409);
    }

    public function testVoidFormForRealEmailSendIs404(): void
    {
        $this->login();
        $this->configureMail();
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id']));
        $sid = $this->sendId();

        $this->expectException(PageNotFoundException::class);
        $this->withHeaders(['HX-Request' => 'true'])->get('/sends/' . $sid . '/void');
    }
}
