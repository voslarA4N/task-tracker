<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class AllocationPanelTest extends AuthTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
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

    /**
     * @param array<string, string> $data
     */
    private function submit(string $url, array $data = [], bool $htmx = true): TestResponse
    {
        $this->withSession();
        $headers = $htmx ? ['HX-Request' => 'true'] : [];

        return $this->withHeaders($headers)->call('post', $url, $data + [csrf_token() => csrf_hash()]);
    }

    private function fragment(string $url): TestResponse
    {
        return $this->withHeaders(['HX-Request' => 'true'])->get($url);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function taskWithAllocation(string $month = '2026-10', bool $locked = false): array
    {
        $client = $this->createClient();
        $task   = $this->createTask($client, ['title' => 'Úloha <b>X</b>']);
        $alloc  = $this->createAllocation($task, '8.00', $month);

        if ($locked) {
            $this->lockAllocation($alloc);
        }

        return [$task, $alloc];
    }

    public function testNewPanelFragment(): void
    {
        $task   = $this->createTask($this->createClient());
        $result = $this->fragment('/tasks/' . $task . '/allocations/new');
        $html   = $this->body($result);

        $result->assertStatus(200);
        $this->assertStringContainsString('Vary', implode(',', array_keys($result->response()->headers())));
        $this->assertStringContainsString('HX-Request', $result->response()->getHeaderLine('Vary'));
        $this->assertStringContainsString('offcanvas-bottom', $html);
        $this->assertStringContainsString('x-data="allocForm"', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertMatchesRegularExpression('/value="current"[^>]*checked/', $html);
        $this->assertStringContainsString('Aktuálny (október 2026)', $html);
        $this->assertStringContainsString('Ďalší (november 2026)', $html);
        $this->assertStringContainsString(esc(lang('Allocations.monthOther')), $html);
        $this->assertStringContainsString('type="month"', $html);
        $this->assertStringContainsString('min="2025-10"', $html);
        $this->assertStringContainsString('max="2028-10"', $html);
        $this->assertStringContainsString('value="27.00"', $html);
    }

    public function testNewPanelFullPageWithoutHtmx(): void
    {
        $task = $this->createTask($this->createClient());
        $html = $this->body($this->get('/tasks/' . $task . '/allocations/new'));

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('x-data="allocForm"', $html);
    }

    public function testCreateViaHtmx(): void
    {
        $task   = $this->createTask($this->createClient());
        $result = $this->submit('/tasks/' . $task . '/allocations', ['month_choice' => 'next', 'hours' => '8,5', 'rate' => '27.00']);

        $result->assertStatus(200);
        $this->assertStringContainsString('/tasks/' . $task, $result->response()->getHeaderLine('HX-Location'));
        $this->assertStringContainsString('allocationChanged', $result->response()->getHeaderLine('HX-Trigger'));

        $row = $this->owner()->query('SELECT hours, billing_month, amount FROM billing_allocations')->getRow();
        $this->assertSame('8.50', (string) $row->hours);
        $this->assertSame('2026-11', trim((string) $row->billing_month));
        $this->assertSame('229.50', (string) $row->amount);
    }

    public function testCreateWithoutHtmxRedirects(): void
    {
        $task   = $this->createTask($this->createClient());
        $result = $this->submit('/tasks/' . $task . '/allocations', ['month_choice' => 'current', 'hours' => '8', 'rate' => '27'], false);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/tasks/' . $task, $result->getRedirectUrl());
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations')->getRow()->c);
    }

    public function testCreateValidationErrors(): void
    {
        $task   = $this->createTask($this->createClient());
        $result = $this->submit('/tasks/' . $task . '/allocations', ['month_choice' => 'current', 'hours' => '0', 'rate' => '27']);

        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString(esc(lang('Allocations.errorHours')), $html);
        $this->assertStringContainsString('aria-invalid', $html);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations')->getRow()->c);
    }

    public function testCreateMonthOutOfRange(): void
    {
        $task   = $this->createTask($this->createClient());
        $result = $this->submit('/tasks/' . $task . '/allocations', ['month_choice' => 'other', 'billing_month_other' => '2030-01', 'hours' => '8', 'rate' => '27']);

        $result->assertStatus(422);
        $this->assertStringContainsString('Október 2025', $this->body($result));
    }

    public function testCreateIgnoresForgedAmount(): void
    {
        $task = $this->createTask($this->createClient());
        $this->submit('/tasks/' . $task . '/allocations', ['month_choice' => 'current', 'hours' => '2', 'rate' => '27', 'amount' => '1', 'invoice_id' => '5']);

        $row = $this->owner()->query('SELECT amount, invoice_id FROM billing_allocations')->getRow();
        $this->assertSame('54.00', (string) $row->amount);
        $this->assertNull($row->invoice_id);
    }

    public function testEditShowsValuesAndDeleteButton(): void
    {
        [, $alloc] = $this->taskWithAllocation();
        $html      = $this->body($this->fragment('/allocations/' . $alloc . '/edit'));

        $this->assertStringContainsString('value="8"', str_replace('8.00', '8', $html));
        $this->assertStringContainsString('hx-get="/allocations/' . $alloc . '/delete"', $html);
        $this->assertStringContainsString(esc(lang('Allocations.delete')), $html);
    }

    public function testUpdateMovesMonth(): void
    {
        [$task, $alloc] = $this->taskWithAllocation();
        $result         = $this->submit('/allocations/' . $alloc, ['month_choice' => 'other', 'billing_month_other' => '2026-12', 'hours' => '8', 'rate' => '27']);

        $result->assertStatus(200);
        $this->assertStringContainsString('/tasks/' . $task, $result->response()->getHeaderLine('HX-Location'));
        $this->assertSame('2026-12', trim((string) $this->owner()->query('SELECT billing_month FROM billing_allocations')->getRow()->billing_month));
    }

    public function testLockedPartIs409OnEditUpdateDelete(): void
    {
        [, $alloc] = $this->taskWithAllocation('2026-10', true);

        $edit = $this->fragment('/allocations/' . $alloc . '/edit');
        $edit->assertStatus(409);
        $this->assertStringContainsString(esc(lang('Allocations.errorLocked')), $this->body($edit));

        $update = $this->submit('/allocations/' . $alloc, ['month_choice' => 'next', 'hours' => '9', 'rate' => '27']);
        $update->assertStatus(409);
        $this->assertStringContainsString(esc(lang('Allocations.errorLocked')), $this->body($update));
        $row = $this->owner()->query('SELECT hours, billing_month FROM billing_allocations')->getRow();
        $this->assertSame('8.00', (string) $row->hours);
        $this->assertSame('2026-10', trim((string) $row->billing_month));

        $this->submit('/allocations/' . $alloc . '/delete')->assertStatus(409);
        $this->assertSame(1, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations')->getRow()->c);
    }

    public function testConfirmDeleteAndDelete(): void
    {
        [, $alloc] = $this->taskWithAllocation();
        $html      = $this->body($this->fragment('/allocations/' . $alloc . '/delete'));

        $this->assertStringContainsString('október 2026', $html);
        $this->assertStringContainsString('216,00', $html);
        $this->assertStringContainsString('Úloha &lt;b&gt;X&lt;/b&gt;', $html);
        $this->assertStringContainsString('btn-danger', $html);
        $this->assertStringContainsString(esc(lang('Allocations.cancel')), $html);

        $result = $this->submit('/allocations/' . $alloc . '/delete');
        $result->assertStatus(200);
        $this->assertStringContainsString('allocationChanged', $result->response()->getHeaderLine('HX-Trigger'));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations')->getRow()->c);
    }

    public function testMissingAllocationIs404(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->get('/allocations/999/edit');
    }

    public function testMissingTaskIs404(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->get('/tasks/999/allocations/new');
    }

    public function testPostWithoutCsrfIs403(): void
    {
        $task   = $this->createTask($this->createClient());
        $result = $this->withHeaders(['HX-Request' => 'true'])->call('post', '/tasks/' . $task . '/allocations', ['hours' => '8']);

        $result->assertStatus(403);
    }
}
