<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Libraries\Auth\TrustedDeviceService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Nedovernostne relacie sa ukoncuju po 2 hodinach necinnosti (D-18), dovernostne nie.
 *
 * @internal
 */
#[Group('feature')]
final class IdleTimeoutTest extends AuthTestCase
{
    private const T0 = '2026-10-24T10:00:00+02:00';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setClock(self::T0);
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function setClock(string $instant): void
    {
        $this->clock = new FrozenClock($instant);
        Services::injectMock('clock', $this->clock);
    }

    private function advance(int $seconds): void
    {
        $this->setClock($this->clock->now()->modify('+' . $seconds . ' seconds')->format(DATE_ATOM));
    }

    private function fetch(string $path): TestResponse
    {
        $this->withSession();

        return $this->get($path);
    }

    public function testSessionCookieLivesThirtyDays(): void
    {
        $this->assertSame(2592000, config('Session')->expiration);
    }

    public function testUntrustedSessionIsEndedAfterTwoHoursOfInactivity(): void
    {
        $this->loginAs($this->createUser());
        $this->fetch('/')->assertStatus(200);

        $this->advance(1 * 3600 + 59 * 60);
        $this->fetch('/')->assertStatus(200);

        $this->advance(2 * 3600 + 60);
        $result = $this->fetch('/');
        $result->assertStatus(302);
        $this->assertStringEndsWith('/login', $result->response()->getHeaderLine('Location'));
        $this->assertSame(1, (int) $this->owner()->query("SELECT count(*) AS c FROM auth_events WHERE event = 'idle_logout'")->getRow()->c);

        $login = $this->fetch('/login');
        $login->assertStatus(200);
        $this->assertStringContainsString(esc(lang('Login.infoIdleLogout')), (string) $login->response()->getBody());
    }

    public function testTrustedSessionIsNotEndedByInactivity(): void
    {
        $user = $this->createUser();
        $this->trustDevice($user);
        $id = (int) $this->owner()->query('SELECT id FROM trusted_devices LIMIT 1')->getRow()->id;
        $this->loginAs($user);
        session()->set('device_id', $id);

        $this->fetch('/')->assertStatus(200);
        $this->advance(3 * 3600);
        $this->fetch('/')->assertStatus(200);
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM auth_events WHERE event = 'idle_logout'")->getRow()->c);
        $this->assertTrue((new TrustedDeviceService())->isActive($id, (int) $user->id, $this->clock->now()));
    }

    public function testHtmxRequestAfterIdleGets401WithRedirect(): void
    {
        $this->loginAs($this->createUser());
        $this->advance(2 * 3600 + 1);
        $this->withSession();
        $result = $this->withHeaders(['HX-Request' => 'true'])->get('/demo');

        $result->assertStatus(401);
        $this->assertSame('/login', $result->response()->getHeaderLine('HX-Redirect'));
    }
}
