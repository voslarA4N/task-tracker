<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\HTTP\UserAgent;

final class DeviceLabel
{
    public static function fromUserAgent(string $ua): string
    {
        $agent = new UserAgent();
        $agent->parse($ua);

        $platform = $agent->getPlatform();
        $mobile   = $agent->getMobile();

        if ($mobile !== '' && str_contains(strtolower($mobile), strtolower('iphone'))) {
            $platform = 'iPhone';
        } elseif ($mobile !== '' && str_contains(strtolower($mobile), strtolower('ipad'))) {
            $platform = 'iPad';
        }

        $browser = $agent->getBrowser();

        if ($platform === '' && $browser === '') {
            return lang('Login.unknownDevice');
        }

        return implode(', ', array_filter([$platform, $browser], static fn (string $v): bool => $v !== ''));
    }
}
