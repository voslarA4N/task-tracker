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

    public function testFromGetKeepsOnlyWhitelistAndTrims(): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('get', ['client' => ' 5 ', 'evil' => 'x', 'month' => '2026-10']);

        $out = PreviewInput::fromGet($request);

        $this->assertSame(PreviewInput::FIELDS, array_keys($out));
        $this->assertSame('5', $out['client']);
        $this->assertSame('', $out['entity']);
    }
}
