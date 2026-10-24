<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use App\Libraries\Auth\BackupCodeService;
use App\Libraries\Auth\TrustedDeviceService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use DateMalformedStringException;
use IntlDateFormatter;

final class Security extends BaseController
{
    private const EVENT_LIMIT = 50;

    public function index(): string
    {
        $user = auth()->user();
        assert($user instanceof User);

        return view('settings/security', $this->pageData($user));
    }

    public function revokeDevice(int $id): ResponseInterface
    {
        $user = auth()->user();
        assert($user instanceof User);
        $userId = (int) $user->id;

        $devices = new TrustedDeviceService();

        if (! $devices->revoke($id, $userId, 'user', service('clock')->now())) {
            throw PageNotFoundException::forPageNotFound();
        }

        (new AuditLog())->record(AuthEvent::DeviceRevoked, true, $userId, null, 'user');

        if ($id === session('device_id')) {
            auth('session')->logout();
            session()->setFlashdata('auth_info', lang('Account.revoked'));

            return redirect()->to('/login')->setCookie($devices->expiredCookie());
        }

        session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.revoked')]);

        return redirect()->to('/settings/security');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(User $user): array
    {
        $userId  = (int) $user->id;
        $now     = service('clock')->now();
        $devices = [];

        foreach ((new TrustedDeviceService())->listForUser($userId, $now) as $device) {
            $devices[] = [
                'id'      => (int) $device['id'],
                'label'   => (string) $device['label'],
                'created' => $this->formatTime((string) $device['created_at']),
                'last'    => $this->formatTime((string) $device['last_used_at']),
                'expires' => $this->formatTime((string) $device['expires_at']),
            ];
        }

        $events = [];

        foreach ((new AuditLog())->latestForUser($userId, self::EVENT_LIMIT) as $event) {
            $locked   = in_array($event['event'], ['lockout', 'locked_attempt'], true);
            $statusOk = $event['success'] && ! $locked;
            $events[] = [
                'time'      => $this->formatTime($event['occurred_at']),
                'ok'        => $statusOk,
                'statusKey' => $statusOk ? 'statusOk' : ($locked ? 'statusLocked' : 'statusFailed'),
                'reasonKey' => 'event_' . $event['event'],
                'ip'        => $event['ip'],
                'device'    => $event['device_label'],
            ];
        }

        $deviceId = session('device_id');

        return [
            'title'           => lang('Account.securityTitle'),
            'remainingCodes'  => (new BackupCodeService())->remaining($userId),
            'devices'         => $devices,
            'currentDeviceId' => is_int($deviceId) ? $deviceId : null,
            'events'          => $events,
        ];
    }

    private function formatTime(string $timestamp): string
    {
        try {
            $unix = service('clock')->now()->modify($timestamp)->getTimestamp();
        } catch (DateMalformedStringException) {
            return $timestamp;
        }

        $formatter = new IntlDateFormatter(
            service('request')->getLocale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            config('App')->appTimezone,
            IntlDateFormatter::GREGORIAN,
            'd. M. yyyy HH:mm',
        );
        $out = $formatter->format($unix);

        return $out === false ? $timestamp : $out;
    }
}
