<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Libraries\Auth\AccountRecovery;
use App\Libraries\Auth\BackupCodeService;
use App\Libraries\Auth\TrustedDeviceService;
use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Shield\Authentication\Passwords;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\Mock\MockEmail;
use Config\Auth;
use Config\Services;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * Nudzova obnova (D-12, D-14): zdielana logika AccountRecovery pre web aj spark.
 *
 * @internal
 */
#[Group('integration')]
final class RecoveryTest extends AuthTestCase
{
    private const NEW_PASSWORD = 'Nove-Heslo-Slnko-2026!';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-10-24T10:00:00+02:00');
        Services::injectMock('clock', $this->clock);
        config('Email')->transport = 'smtp';
        $this->mockEmail();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    private function eventCount(string $event): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM auth_events WHERE event = ?', [$event])->getRow()->c;
    }

    private function mail(): MockEmail
    {
        $mail = service('email');
        $this->assertInstanceOf(MockEmail::class, $mail);

        return $mail;
    }

    private function prepared(): User
    {
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        (new BackupCodeService())->generate((int) $user->id, $this->clock->now());
        $this->trustDevice($user);
        $this->trustDevice($user);
        $this->owner()->query(
            "INSERT INTO auth_identities (user_id, type, secret, created_at) VALUES (?, 'totp_gateway', 'x', now())",
            [$user->id],
        );

        return $user;
    }

    public function testResetTwoFactorWipesEverythingAndNotifies(): void
    {
        $user  = $this->prepared();
        $epoch = (new TrustedDeviceService())->currentEpoch((int) $user->id);

        (new AccountRecovery())->resetTwoFactor($user, $this->clock->now());

        $sec = $this->owner()->query('SELECT totp_secret_enc, totp_enabled_at, totp_last_step FROM user_security WHERE user_id = ?', [$user->id])->getRowArray();
        $this->assertNull($sec['totp_secret_enc']);
        $this->assertNull($sec['totp_enabled_at']);
        $this->assertNull($sec['totp_last_step']);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM backup_codes WHERE user_id = ?', [$user->id])->getRow()->c);
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL', [$user->id])->getRow()->c);
        $this->assertSame($epoch + 1, (new TrustedDeviceService())->currentEpoch((int) $user->id));
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM auth_identities WHERE user_id = ? AND type = 'totp_gateway'", [$user->id])->getRow()->c);
        $this->assertSame(1, $this->eventCount('totp_reset_cli'));
        $this->assertSame(['owner@example.test'], $this->mail()->archive['recipients'] ?? null);
        $this->assertStringContainsString('reset', strtolower((string) $this->mail()->archive['subject']));
    }

    public function testSetPasswordViaCliChangesPasswordRevokesDevicesAndNotifies(): void
    {
        $user  = $this->prepared();
        $epoch = (new TrustedDeviceService())->currentEpoch((int) $user->id);

        (new AccountRecovery())->setPassword($user, self::NEW_PASSWORD, 'cli', $this->clock->now());

        $fresh = model(UserModel::class)->findById((int) $user->id);
        $this->assertInstanceOf(User::class, $fresh);
        $this->assertTrue((new Passwords(config(Auth::class)))->verify(self::NEW_PASSWORD, (string) $fresh->password_hash));
        $this->assertFalse((new Passwords(config(Auth::class)))->verify(self::PASSWORD, (string) $fresh->password_hash));
        $this->assertSame(0, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL', [$user->id])->getRow()->c);
        $this->assertSame($epoch + 1, (new TrustedDeviceService())->currentEpoch((int) $user->id));
        $this->assertSame(1, $this->eventCount('password_set_cli'));
        $this->assertSame(['owner@example.test'], $this->mail()->archive['recipients'] ?? null);
    }

    public function testWeakPasswordThrowsAndChangesNothing(): void
    {
        $user  = $this->prepared();
        $epoch = (new TrustedDeviceService())->currentEpoch((int) $user->id);
        $hash  = (string) model(UserModel::class)->findById((int) $user->id)->password_hash;

        try {
            (new AccountRecovery())->setPassword($user, 'kratke', 'cli', $this->clock->now());
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame($hash, (string) model(UserModel::class)->findById((int) $user->id)->password_hash);
        $this->assertSame($epoch, (new TrustedDeviceService())->currentEpoch((int) $user->id));
        $this->assertSame(2, (int) $this->owner()->query('SELECT count(*) AS c FROM trusted_devices WHERE user_id = ? AND revoked_at IS NULL', [$user->id])->getRow()->c);
        $this->assertSame(0, $this->eventCount('password_set_cli'));
    }
}
