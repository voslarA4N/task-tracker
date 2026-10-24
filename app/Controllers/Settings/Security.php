<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\BackupCodeService;
use App\Libraries\Auth\TrustedDeviceService;
use CodeIgniter\Shield\Entities\User;
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
        $unix = strtotime($timestamp);

        if ($unix === false) {
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
