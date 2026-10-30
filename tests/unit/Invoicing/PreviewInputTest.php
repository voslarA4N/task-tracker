<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\PreviewInput;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class PreviewInputTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    public function testMonthTooOldIsRejected(): void
    {
        $this->assertArrayHasKey('month', PreviewInput::validate(['month' => '2024-09']));
    }

    public function testMonthTooFarAheadIsRejected(): void
    {
        $this->assertArrayHasKey('month', PreviewInput::validate(['month' => '2026-12']));
    }

    public function testMonthInRangeIsAccepted(): void
    {
        $this->assertSame([], PreviewInput::validate(['month' => '2026-09']));
        $this->assertSame([], PreviewInput::validate(['month' => '2024-10']));
        $this->assertSame([], PreviewInput::validate(['month' => '2026-11']));
    }

    public function testDueBeforeIssueIsRejected(): void
    {
        $errors = PreviewInput::validate(['issue_date' => '2026-10-27', 'due_date' => '2026-10-01']);

        $this->assertArrayHasKey('due_date', $errors);
    }

    public function testIssueDateFarInFutureIsRejected(): void
    {
        $this->assertArrayHasKey('issue_date', PreviewInput::validate(['issue_date' => '2062-10-27']));
        $this->assertArrayHasKey('issue_date', PreviewInput::validate(['issue_date' => '2026-11-04']));
        $this->assertSame([], PreviewInput::validate(['issue_date' => '2026-11-03']));
        $this->assertSame([], PreviewInput::validate(['issue_date' => '2025-01-15']));
    }

    public function testDeliveryDateOutsideBillingMonthWindowIsRejected(): void
    {
        $this->assertArrayHasKey('delivery_date', PreviewInput::validate(['month' => '2026-09', 'delivery_date' => '2062-09-30']));
        $this->assertArrayHasKey('delivery_date', PreviewInput::validate(['month' => '2026-09', 'delivery_date' => '2026-07-31']));
        $this->assertArrayHasKey('delivery_date', PreviewInput::validate(['month' => '2026-09', 'delivery_date' => '2026-11-01']));
        $this->assertSame([], PreviewInput::validate(['month' => '2026-09', 'delivery_date' => '2026-09-30']));
        $this->assertSame([], PreviewInput::validate(['month' => '2026-09', 'delivery_date' => '2026-08-01']));
        $this->assertSame([], PreviewInput::validate(['month' => '2026-09', 'delivery_date' => '2026-10-31']));
        $this->assertSame([], PreviewInput::validate(['month' => '2026-11', 'delivery_date' => '2026-11-30']));
    }

    public function testDueDateMoreThanAYearAfterIssueIsRejected(): void
    {
        $this->assertArrayHasKey('due_date', PreviewInput::validate(['issue_date' => '2026-10-27', 'due_date' => '2027-10-28']));
        $this->assertSame([], PreviewInput::validate(['issue_date' => '2026-10-27', 'due_date' => '2027-10-27']));
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->assertArrayHasKey('issue_date', PreviewInput::validate(['issue_date' => '2026-02-30']));
    }

    public function testClientMustBeDigits(): void
    {
        $this->assertArrayHasKey('client', PreviewInput::validate(['client' => 'abc']));
        $this->assertArrayHasKey('entity', PreviewInput::validate(['entity' => '1; DROP']));
        $this->assertSame([], PreviewInput::validate(['client' => '12', 'entity' => '']));
    }

    public function testFxFieldsAreWhitelistedAndOverlongDropped(): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('get', ['fx_mode' => 'manual', 'fx_rate' => '4,3', 'fx_source' => 'NBP', 'fx_date' => str_repeat('1', 33)]);

        $out = PreviewInput::fromGet($request);

        $this->assertSame('manual', $out['fx_mode']);
        $this->assertSame('4,3', $out['fx_rate']);
        $this->assertSame('', $out['fx_date']);
        $this->assertArrayNotHasKey('fx_source', $out);
        $this->assertArrayNotHasKey('fx_table_no', $out);
    }

    public function testFromGetKeepsOnlyWhitelistAndTrims(): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('get', ['client' => ' 5 ', 'evil' => 'x', 'month' => '2026-10']);

        $out = PreviewInput::fromGet($request);

        $this->assertSame([...PreviewInput::FIELDS, 'payment_method', 'payment_note', 'payment_note_dirty'], array_keys($out));
        $this->assertSame('transfer', $out['payment_method']);
        $this->assertSame('0', $out['payment_note_dirty']);
        $this->assertSame('5', $out['client']);
        $this->assertSame('', $out['entity']);
    }

    public function testInvalidPaymentFieldsAreSilentlyDefaulted(): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('get', ['payment_method' => 'bitcoin', 'payment_note' => str_repeat('a', 501), 'payment_note_dirty' => '1']);

        $out = PreviewInput::fromGet($request);

        $this->assertSame('transfer', $out['payment_method']);
        $this->assertSame('', $out['payment_note']);
        $this->assertSame('0', $out['payment_note_dirty']);
    }
}
