<?php

declare(strict_types=1);

namespace Tests\Unit\Work;

use App\Libraries\Platform\FrozenClock;
use App\Libraries\Work\AllocationInput;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class AllocationInputTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function valid(array $over = []): array
    {
        return $over + ['hours' => '8,5', 'billing_month' => '2026-10', 'rate' => '27'];
    }

    public function testValidInputNormalizes(): void
    {
        $this->assertSame([], AllocationInput::validate($this->valid()));
        $n = AllocationInput::normalize($this->valid());
        $this->assertSame('8.50', $n['hours']);
        $this->assertSame('2026-10', $n['billing_month']);
        $this->assertSame('27.00', $n['rate']);
    }

    public function testAmountOverflowIsRejected(): void
    {
        // CR-01: suma casti musi ist do numeric(12,2), inak 422 namiesto OverflowException (500).
        $errors = AllocationInput::validate($this->valid(['hours' => '10000', 'rate' => '1000000']));
        $this->assertSame(lang('Allocations.errorAmount'), $errors['rate'] ?? null);
        $this->assertSame([], AllocationInput::validate($this->valid(['hours' => '99999,99', 'rate' => '99999,99'])));
    }

    public function testMonthChoiceCurrent(): void
    {
        $v = AllocationInput::fromPost($this->request(['hours' => '1', 'rate' => '1', 'month_choice' => 'current']));
        $this->assertSame('2026-10', $v['billing_month']);
    }

    public function testMonthChoiceNext(): void
    {
        $v = AllocationInput::fromPost($this->request(['month_choice' => 'next']));
        $this->assertSame('2026-11', $v['billing_month']);
    }

    public function testMonthChoiceOther(): void
    {
        $v = AllocationInput::fromPost($this->request(['month_choice' => 'other', 'billing_month_other' => '2027-03']));
        $this->assertSame('2027-03', $v['billing_month']);
    }

    public function testDirectBillingMonthWins(): void
    {
        $v = AllocationInput::fromPost($this->request(['month_choice' => 'next', 'billing_month' => '2026-12']));
        $this->assertSame('2026-12', $v['billing_month']);
    }

    public function testMonthOutOfRangeAndInvalid(): void
    {
        $this->assertArrayHasKey('billing_month', AllocationInput::validate($this->valid(['billing_month' => '2025-09'])));
        $this->assertArrayHasKey('billing_month', AllocationInput::validate($this->valid(['billing_month' => '2026-13'])));
        $this->assertArrayHasKey('billing_month', AllocationInput::validate($this->valid(['billing_month' => ''])));
    }

    public function testZeroHours(): void
    {
        $this->assertArrayHasKey('hours', AllocationInput::validate($this->valid(['hours' => '0'])));
        $this->assertArrayNotHasKey('hours', AllocationInput::validate($this->valid(['hours' => '0']), true));
        $this->assertArrayHasKey('hours', AllocationInput::validate($this->valid(['hours' => '8.555']), true));
    }

    public function testRateNormalizes(): void
    {
        $this->assertSame('27.50', AllocationInput::normalize($this->valid(['rate' => '27,5']))['rate']);
        $this->assertArrayHasKey('rate', AllocationInput::validate($this->valid(['rate' => 'abc'])));
    }

    public function testFromPostIgnoresForbiddenFields(): void
    {
        $v = AllocationInput::fromPost($this->request([
            'hours'  => '2', 'billing_month' => '2026-10', 'rate' => '10',
            'amount' => '999', 'currency' => 'USD', 'invoice_id' => '5', 'task_id' => '9',
        ]));
        $this->assertSame(['hours', 'billing_month', 'rate'], array_keys($v));
    }

    /**
     * @param array<string, string> $post
     */
    private function request(array $post): IncomingRequest
    {
        $config  = new App();
        $request = new IncomingRequest($config, new SiteURI($config), null, new UserAgent());
        $request->setGlobal('post', $post);

        return $request;
    }
}
