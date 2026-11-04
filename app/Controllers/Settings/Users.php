<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Auth\UserDirectory;
use DateMalformedStringException;
use IntlDateFormatter;

/**
 * Nastavenia, Používatelia: iba čítanie (UI-03, D-16). Účty sa vytvárajú príkazom spark.
 */
final class Users extends BaseController
{
    public function index(): string
    {
        $users = array_map(function (array $u): array {
            $u['last_login_label'] = $u['last_login'] !== null ? $this->formatTime($u['last_login']) : null;

            return $u;
        }, (new UserDirectory())->list());

        return view('settings/users/index', ['users' => $users, 'mainWide' => false]);
    }

    private function formatTime(string $timestamp): string
    {
        try {
            $unix = service('clock')->now()->modify($timestamp)->getTimestamp();
        } catch (DateMalformedStringException) {
            return $timestamp;
        }

        $formatter = new IntlDateFormatter(
            $this->request->getLocale(),
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
