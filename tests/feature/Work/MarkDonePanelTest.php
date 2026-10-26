<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class MarkDonePanelTest extends AuthTestCase
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

    private function taskWithPart(string $partHours = '16.00', string $title = 'Oprava exportu'): int
    {
        $task = $this->createTask($this->createClient(), ['title' => $title]);

        if ($partHours !== '') {
            $this->createAllocation($task, $partHours, '2026-10');
        }

        return $task;
    }

    private function status(int $task): string
    {
        return (string) $this->owner()->query('SELECT status FROM tasks WHERE id = ' . $task)->getRow()->status;
    }

    private function partCount(int $task): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM billing_allocations WHERE task_id = ' . $task)->getRow()->c;
    }

    public function testDetailShowsDoneActionWhenInProgress(): void
    {
        $task = $this->taskWithPart();
        $html = $this->body($this->get('/tasks/' . $task));

        $this->assertStringContainsString(lang('Allocations.doneOpen'), $html);
        $this->assertStringContainsString('hx-get="/tasks/' . $task . '/done"', $html);
        $this->assertStringContainsString('hx-target="#sheet"', $html);
        $this->assertStringContainsString('href="/tasks/' . $task . '/done"', $html);
        $this->assertStringNotContainsString(lang('Allocations.reopenOpen'), $html);
    }

    public function testDetailShowsReopenWhenDone(): void
    {
        $task = $this->createTask($this->createClient(), ['title' => 'Hotová <b>X</b>', 'status' => 'done', 'done_at' => '2026-10-20 10:00:00+02']);
        $html = $this->body($this->get('/tasks/' . $task));

        $this->assertStringContainsString(lang('Allocations.reopenOpen'), $html);
        $this->assertStringNotContainsString('hx-get="/tasks/' . $task . '/done"', $html);
        $this->assertStringContainsString('action="/tasks/' . $task . '/reopen"', $html);
        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
        $this->assertStringContainsString(esc(lang('Allocations.reopenConfirm', ['Hotová <b>X</b>'])), $html);
    }

    public function testDonePanelDefaultsToRemaining(): void
    {
        $task   = $this->taskWithPart('16.00');
        $result = $this->fragment('/tasks/' . $task . '/done');
        $html   = $this->body($result);

        $result->assertStatus(200);
        $this->assertStringContainsString('offcanvas-bottom', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringContainsString('Označiť ako zapracovanú', $html);
        $this->assertStringContainsString('Oprava exportu · 40 h', $html);
        $this->assertMatchesRegularExpression('/value="current"[^>]*checked/', $html);
        $this->assertStringContainsString('Aktuálny (október 2026)', $html);
        $this->assertStringContainsString('Ďalší (november 2026)', $html);
        $this->assertStringContainsString(esc(lang('Allocations.monthOther')), $html);
        $this->assertStringContainsString('name="hours" value="24"', $html);
        $this->assertStringContainsString('Zostatok: 24 h', $html);
        $this->assertStringContainsString('Potvrdiť', $html);
        $this->assertStringContainsString('Zrušiť', $html);
        $this->assertStringContainsString('data-submit-once', $html);
        $this->assertStringContainsString('x-data="allocForm"', $html);
        $this->assertStringContainsString('hx-post="/tasks/' . $task . '/done"', $html);
    }

    public function testDonePanelZeroRemaining(): void
    {
        $task = $this->taskWithPart('46.00');
        $html = $this->body($this->fragment('/tasks/' . $task . '/done'));

        $this->assertStringContainsString('name="hours" value="0"', $html);
        $this->assertStringContainsString('Nacenené hodiny sú už rozdelené. Pri 0 h sa nevytvorí nová časť.', $html);
    }

    public function testDonePanelFullPageWithoutHtmx(): void
    {
        $task = $this->taskWithPart();
        $html = $this->body($this->get('/tasks/' . $task . '/done'));

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('x-data="allocForm"', $html);
    }

    public function testMarkDoneCreatesPartAndRedirectsViaHtmx(): void
    {
        $task   = $this->taskWithPart('16.00');
        $result = $this->submit('/tasks/' . $task . '/done', ['month_choice' => 'current', 'hours' => '24']);

        $result->assertStatus(200);
        $this->assertStringEndsWith('/tasks/' . $task, $result->response()->getHeaderLine('HX-Location'));
        $this->assertStringContainsString('allocationChanged', $result->response()->getHeaderLine('HX-Trigger'));

        $row = $this->owner()->query('SELECT status, done_at FROM tasks WHERE id = ' . $task)->getRow();
        $this->assertSame('done', (string) $row->status);
        $this->assertNotNull($row->done_at);

        $part = $this->owner()->query('SELECT hours, rate, billing_month FROM billing_allocations WHERE task_id = ' . $task . ' ORDER BY id DESC LIMIT 1')->getRow();
        $this->assertSame('24.00', (string) $part->hours);
        $this->assertSame('27.00', (string) $part->rate);
        $this->assertSame('2026-10', trim((string) $part->billing_month));
        $this->assertSame(2, $this->partCount($task));

        $detail = $this->body($this->get('/tasks/' . $task));
        $this->assertStringContainsString(esc(lang('Allocations.doneFlash', ['24', 'október 2026'])), $detail);
    }

    public function testMarkDoneZeroHoursCreatesNoPart(): void
    {
        $task   = $this->taskWithPart('46.00');
        $result = $this->submit('/tasks/' . $task . '/done', ['month_choice' => 'current', 'hours' => '0']);

        $result->assertStatus(200);
        $this->assertSame('done', $this->status($task));
        $this->assertSame(1, $this->partCount($task));

        $detail = $this->body($this->get('/tasks/' . $task));
        $this->assertStringContainsString(esc(lang('Allocations.doneFlashNoPart')), $detail);
    }

    public function testSecondSubmitIsConflict(): void
    {
        $task = $this->taskWithPart('16.00');
        $this->submit('/tasks/' . $task . '/done', ['month_choice' => 'current', 'hours' => '24'])->assertStatus(200);

        $second = $this->submit('/tasks/' . $task . '/done', ['month_choice' => 'current', 'hours' => '24']);

        $second->assertStatus(409);
        $this->assertStringContainsString(esc(lang('Allocations.errorAlreadyDone')), $this->body($second));
        $this->assertSame(2, $this->partCount($task));
    }

    public function testInvalidHoursReturns422(): void
    {
        $task   = $this->taskWithPart('16.00');
        $result = $this->submit('/tasks/' . $task . '/done', ['month_choice' => 'current', 'hours' => 'abc']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Allocations.errorHours')), $this->body($result));
        $this->assertSame('in_progress', $this->status($task));
        $this->assertSame(1, $this->partCount($task));
    }

    public function testForgedRateIsIgnored(): void
    {
        $task = $this->taskWithPart('');
        $this->submit('/tasks/' . $task . '/done', ['month_choice' => 'current', 'hours' => '10', 'rate' => '1'])->assertStatus(200);

        $rate = (string) $this->owner()->query('SELECT rate FROM billing_allocations WHERE task_id = ' . $task)->getRow()->rate;
        $this->assertSame('27.00', $rate);
    }

    public function testDonePanelOfDoneTaskIs409AndMissingIs404(): void
    {
        $task = $this->createTask($this->createClient(), ['status' => 'done', 'done_at' => '2026-10-20 10:00:00+02']);

        $this->fragment('/tasks/' . $task . '/done')->assertStatus(409);
        $this->fragment('/tasks/999999/done')->assertStatus(404);
    }

    public function testReopenKeepsParts(): void
    {
        $task = $this->createTask($this->createClient(), ['status' => 'done', 'done_at' => '2026-10-20 10:00:00+02']);
        $this->createAllocation($task, '40.00', '2026-10');

        $result = $this->submit('/tasks/' . $task . '/reopen', [], false);

        $result->assertStatus(302);
        $this->assertStringEndsWith('/tasks/' . $task, $result->getRedirectUrl());
        $this->assertSame('in_progress', $this->status($task));
        $this->assertSame(1, $this->partCount($task));

        $detail = $this->body($this->get('/tasks/' . $task));
        $this->assertStringContainsString('Úloha je opäť v stave V práci.', $detail);
    }

    public function testReopenOfInProgressIs409(): void
    {
        $task   = $this->taskWithPart('');
        $result = $this->submit('/tasks/' . $task . '/reopen', [], false);

        $result->assertStatus(409);
        $this->assertStringContainsString(esc(lang('Allocations.errorNotDone')), $this->body($result));
    }
}
