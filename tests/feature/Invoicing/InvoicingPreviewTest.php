<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Platform\MoneyFormat;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class InvoicingPreviewTest extends AuthTestCase
{
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    private function fragment(string $url): TestResponse
    {
        return $this->withHeaders(['HX-Request' => 'true'])->get($url);
    }

    private function counts(): string
    {
        return json_encode($this->owner()->query('SELECT (SELECT count(*) FROM invoices) AS i, (SELECT count(*) FROM invoice_counters) AS c, (SELECT count(*) FROM audit_log) AS a')->getRowArray(), JSON_THROW_ON_ERROR);
    }

    public function testNoClientShowsEmptyState(): void
    {
        $html = $this->body($this->get('/invoices/new'));

        $this->assertStringContainsString('Najprv pridajte klienta', $html);
        $this->assertStringNotContainsString('Vystaviť faktúru', $html);
    }

    public function testDefaultsAndPreviewWithoutNumber(): void
    {
        $s = $this->issueSetup(['parts' => [['10.50', '2026-09'], ['4.00', '2026-10']]]);

        $before = $this->counts();
        $result = $this->get('/invoices/new');
        $html   = $this->body($result);

        $result->assertStatus(200);
        $this->assertStringContainsString('value="2026-09"', $html);
        $this->assertStringContainsString('value="2026-10-27"', $html);
        $this->assertStringContainsString('value="2026-09-30"', $html);
        $this->assertStringContainsString('value="2026-11-10"', $html);
        $this->assertStringContainsString(esc(MoneyFormat::format('283.50', 'EUR')), $html);
        $this->assertStringContainsString('np', $html);
        $this->assertStringContainsString('id="inv-issue-form"', $html);
        $this->assertStringContainsString('value="' . $s['parts'][0] . '"', $html);
        $this->assertStringNotContainsString('value="' . $s['parts'][1] . '"', $html);
        $this->assertStringNotContainsString('202609001', $html);
        $this->assertStringNotContainsString('202610001', $html);
        $this->assertSame($before, $this->counts());
    }

    public function testFragmentOnlyAndEndedEntityNotOffered(): void
    {
        $s = $this->issueSetup();
        $this->createEntity('SK', ['active_to' => '2026-10-15', 'name_internal' => 'Stara firma']);

        $result = $this->fragment('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-10');
        $html   = $this->body($result);

        $result->assertStatus(200);
        $this->assertSame('HX-Request', $result->response()->getHeaderLine('Vary'));
        $this->assertStringStartsWith('<div id="inv-preview"', ltrim($html));
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('Stara firma', $html);
    }

    public function testCurrencyMismatchShowsDangerAndDisablesButton(): void
    {
        $s = $this->issueSetup();
        $this->owner()->query("UPDATE clients SET currency = 'PLN' WHERE id = :c:", ['c' => $s['client']]);
        $this->owner()->query("UPDATE billing_allocations SET currency = 'PLN' WHERE id = :p:", ['p' => $s['parts'][0]]);

        $html = $this->body($this->fragment('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-10'));

        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('Faktúra v mene PLN sa nedá vystaviť zo subjektu s menou EUR', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*form="inv-issue-form"[^>]*\sdisabled/', $html);
    }

    public function testBackdateWarningRequiresConfirmation(): void
    {
        $s = $this->issueSetup(['parts' => [['10.00', '2026-10'], ['5.00', '2026-10']]]);
        $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));

        $html = $this->body($this->fragment('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-10&issue_date=2026-10-20'));

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('Pozor:', $html);
        $this->assertStringContainsString('name="confirm_backdate"', $html);
        $this->assertStringContainsString('data-needs-confirm="1"', $html);
        $this->assertStringContainsString('202610001', $html);
    }

    public function testNothingToInvoiceState(): void
    {
        $s    = $this->issueSetup(['parts' => [['10.00', '2026-10']]]);
        $html = $this->body($this->fragment('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-08'));

        $this->assertStringContainsString('Nič na fakturáciu', $html);
        $this->assertStringNotContainsString('id="inv-issue-form"', $html);
    }

    public function testInvalidMonthReturns422(): void
    {
        $s      = $this->issueSetup();
        $result = $this->fragment('/fragments/invoice-preview?client=' . $s['client'] . '&month=2030-01');

        $result->assertStatus(422);
        $this->assertStringContainsString('invalid-feedback', $this->body($result));
    }

    public function testPreviewWritesNothing(): void
    {
        $s      = $this->issueSetup();
        $before = $this->counts();

        $this->fragment('/fragments/invoice-preview?client=' . $s['client'] . '&month=2026-10');
        $this->get('/invoices/new');

        $this->assertSame($before, $this->counts());
    }

    public function testIssueFormAndPickerMarkup(): void
    {
        $s    = $this->issueSetup(['parts' => [['2.00', '2026-10'], ['3.00', '2026-10']]]);
        $html = $this->body($this->get('/invoices/new?client=' . $s['client'] . '&month=2026-10'));

        $this->assertStringContainsString('<form id="inv-issue-form" method="post" action="/invoices/issue">', $html);
        $this->assertSame(2, substr_count($html, 'name="part_ids[]"'));
        $this->assertMatchesRegularExpression('/name="preview_token" value="[0-9a-f]{64}"/', $html);
        $this->assertMatchesRegularExpression('/name="request_key" value="[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"/', $html);
        $this->assertStringContainsString(csrf_token(), $html);
        $this->assertStringContainsString('form="inv-issue-form"', $html);
        $this->assertStringContainsString('hx-get="/fragments/invoice-preview"', $html);
        $this->assertStringContainsString('hx-trigger="change"', $html);
        $this->assertStringContainsString('hx-target="#inv-preview"', $html);
        $this->assertStringContainsString('hx-push-url="true"', $html);
        $this->assertStringContainsString('Zobraziť náhľad', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-can-issue="1"', $html);
    }
}
