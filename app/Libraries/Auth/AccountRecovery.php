<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Authentication\Actions\TotpAction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Authentication\Passwords;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use Config\Auth;
use Config\Database;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Zdielana obnova pristupu (D-12, D-17): zmena hesla a reset 2FA. Nikdy nikoho neprihlasuje.
 * Kazda zmena zneplatni dovernost (epocha + odvolanie zariadeni).
 */
final class AccountRecovery
{
    public const SOURCES = ['reset', 'cli', 'settings'];
    private const FORMAT = 'Y-m-d H:i:sP';

    private readonly TrustedDeviceService $devices;
    private readonly BackupCodeService $backupCodes;
    private readonly AuditLog $audit;
    private readonly SecurityNotifier $notifier;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?TrustedDeviceService $devices = null,
        ?BackupCodeService $backupCodes = null,
        ?AuditLog $audit = null,
        ?SecurityNotifier $notifier = null,
    ) {
        $this->devices     = $devices ?? new TrustedDeviceService();
        $this->backupCodes = $backupCodes ?? new BackupCodeService();
        $this->audit       = $audit ?? new AuditLog();
        $this->notifier    = $notifier ?? new SecurityNotifier();
    }

    /**
     * Over silu hesla bez zmeny stavu.
     *
     * @throws InvalidArgumentException
     */
    public function assertPasswordAcceptable(User $user, string $password): void
    {
        $check = (new Passwords(config(Auth::class)))->check($password, new User(['email' => $user->email, 'password' => $password]));

        if (! $check->isOK()) {
            throw new InvalidArgumentException($check->reason());
        }
    }

    /**
     * @param string $source jedno z SOURCES: reset, cli, settings
     *
     * @throws InvalidArgumentException pri slabom hesle alebo neznamom zdroji (nic sa nezmeni)
     */
    public function setPassword(User $user, string $password, string $source, DateTimeImmutable $now): void
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unknown password source: ' . $source);
        }

        $this->assertPasswordAcceptable($user, $password);

        $userId = (int) $user->id;
        $db     = $this->db();
        $event  = match ($source) {
            'reset' => AuthEvent::PasswordResetDone,
            'cli'   => AuthEvent::PasswordSetCli,
            default => AuthEvent::PasswordChanged,
        };

        $db->transException(true)->transStart();

        try {
            $user->setPassword($password);
            model(UserModel::class)->save($user);
            $this->devices->bumpEpoch($userId, $now);
            $this->devices->revokeAll($userId, 'password_' . $source, $now);
            $db->query('DELETE FROM password_resets WHERE user_id = :u: AND used_at IS NULL', ['u' => $userId]);
            $this->audit->record($event, true, $userId);
            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->transException(false);
        }

        $this->notifier->notify(
            $source === 'reset' ? SecurityNotifier::PASSWORD_RESET : SecurityNotifier::PASSWORD_CHANGED,
            (string) $user->email,
            $this->locale($userId),
            ['at' => $now],
        );
    }

    public function resetTwoFactor(User $user, DateTimeImmutable $now): void
    {
        $userId = (int) $user->id;
        $db     = $this->db();

        $db->transException(true)->transStart();

        try {
            $db->query(
                'UPDATE user_security SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = :now: WHERE user_id = :u:',
                ['now' => $now->format(self::FORMAT), 'u' => $userId],
            );
            $this->backupCodes->deleteAll($userId);
            $this->devices->revokeAll($userId, 'totp_reset', $now);
            $this->devices->bumpEpoch($userId, $now);
            model(UserIdentityModel::class)->deleteIdentitiesByType($user, TotpAction::TYPE);
            $this->audit->record(AuthEvent::TotpResetCli, true, $userId);
            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->transException(false);
        }

        $this->notifier->notify(SecurityNotifier::TOTP_RESET, (string) $user->email, $this->locale($userId), ['at' => $now]);
    }

    private function locale(int $userId): string
    {
        $row = $this->db()->table('user_security')->select('locale')->where('user_id', $userId)->get()->getRow();

        return $row === null ? 'sk' : (string) $row->locale;
    }

    /**
     * @return BaseConnection<mixed, mixed>
     */
    private function db(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
