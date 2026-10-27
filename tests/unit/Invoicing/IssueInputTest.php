<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\IssueCommand;
use App\Libraries\Invoicing\IssueInput;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class IssueInputTest extends CIUnitTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const KEY   = '0b8a2f3e-1c4d-4e5f-8a9b-0c1d2e3f4a5b';

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

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'client_id'        => '5', 'billing_month' => '2026-10', 'entity_id' => '2',
            'part_ids'         => ['11', '7'], 'preview_token' => self::TOKEN, 'request_key' => self::KEY,
            'issue_date'       => '2026-10-27', 'delivery_date' => '2026-10-31', 'due_date' => '2026-11-10',
            'confirm_backdate' => '1',
        ];
    }

    public function testValidInputHasNoErrors(): void
    {
        $this->assertSame([], IssueInput::validate($this->valid()));
    }

    public function testFarFutureDatesAreRejected(): void
    {
        $v = ['issue_date' => '2062-10-27', 'delivery_date' => '2062-10-31', 'due_date' => '2062-11-10'] + $this->valid();

        $errors = IssueInput::validate($v);

        $this->assertArrayHasKey('issue_date', $errors);
        $this->assertArrayHasKey('delivery_date', $errors);
    }

    public function testMissingPreviewTokenIsRejected(): void
    {
        $v = $this->valid();
        unset($v['preview_token']);

        $this->assertArrayHasKey('preview_token', IssueInput::validate($v));
    }

    public function testRequestKeyMustBeUuid(): void
    {
        $this->assertArrayHasKey('request_key', IssueInput::validate(['request_key' => 'abc'] + $this->valid()));
    }

    public function testPartIdsEmptyOrNonNumericAreRejected(): void
    {
        $this->assertArrayHasKey('part_ids', IssueInput::validate(['part_ids' => []] + $this->valid()));
        $this->assertArrayHasKey('part_ids', IssueInput::validate(['part_ids' => ['1', 'x']] + $this->valid()));
    }

    public function testDueBeforeIssueIsRejected(): void
    {
        $this->assertArrayHasKey('due_date', IssueInput::validate(['due_date' => '2026-10-01'] + $this->valid()));
    }

    public function testFromPostIgnoresMoneyFields(): void
    {
        $request = new IncomingRequest(new App(), new SiteURI(new App()), null, new UserAgent());
        $request->setGlobal('post', ['total' => '1.00', 'number' => '9', 'amount' => '5', 'currency' => 'USD', 'client_id' => '5', 'part_ids' => ['3', ['x']]]);

        $out = IssueInput::fromPost($request);

        $this->assertArrayNotHasKey('total', $out);
        $this->assertArrayNotHasKey('number', $out);
        $this->assertArrayNotHasKey('amount', $out);
        $this->assertArrayNotHasKey('currency', $out);
        $this->assertSame('5', $out['client_id']);
        $this->assertSame(['3'], $out['part_ids']);
    }

    public function testToCommandSortsPartsAndReadsBackdateFlag(): void
    {
        $cmd = IssueInput::toCommand($this->valid(), 3);

        $this->assertSame([7, 11], $cmd->partIds);
        $this->assertTrue($cmd->confirmBackdate);
        $this->assertSame(3, $cmd->userId);

        $v = $this->valid();
        unset($v['confirm_backdate']);
        $this->assertFalse(IssueInput::toCommand($v, null)->confirmBackdate);
    }

    public function testPaymentDefaultsWithoutError(): void
    {
        $this->assertSame([], IssueInput::validate($this->valid()));
        $cmd = IssueInput::toCommand($this->valid(), 1);
        $this->assertSame('transfer', $cmd->paymentMethod);
        $this->assertNull($cmd->paymentNote);
    }

    public function testInvalidPaymentMethodIsRejected(): void
    {
        $errors = IssueInput::validate(['payment_method' => 'bitcoin'] + $this->valid());
        $this->assertArrayHasKey('payment_method', $errors);
    }

    public function testTooLongNoteIsRejected(): void
    {
        $this->assertArrayHasKey('payment_note', IssueInput::validate(['payment_note' => str_repeat('a', 501)] + $this->valid()));
        $this->assertSame([], IssueInput::validate(['payment_note' => str_repeat('á', 500)] + $this->valid()));
    }

    public function testNoteIsNormalized(): void
    {
        $cmd = IssueInput::toCommand(['payment_note' => "  Úroky z omeškania 0,05 % denne.\r\n ", 'payment_method' => 'cash'] + $this->valid(), 1);
        $this->assertSame('Úroky z omeškania 0,05 % denne.', $cmd->paymentNote);
        $this->assertSame('cash', $cmd->paymentMethod);
        $this->assertNull(IssueInput::toCommand(['payment_note' => '  '] + $this->valid(), 1)->paymentNote);
    }

    public function testCommandRejectsInvalidPayment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IssueCommand(5, '2026-10', 2, '2026-10-27', '2026-10-31', '2026-11-10', [7], self::TOKEN, self::KEY, false, null, 'wire');
    }
}
