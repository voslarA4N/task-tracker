<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

class AuthGroups extends ShieldAuthGroups
{
    /**
     * Najmensie prava (registracia je aj tak vypnuta).
     */
    public string $defaultGroup = 'accountant';

    /**
     * @var array<string, array<string, string>>
     */
    public array $groups = [
        'admin' => [
            'title'       => 'Admin',
            'description' => 'Vlastník, plný prístup',
        ],
        'accountant' => [
            'title'       => 'Účtovníčka',
            'description' => 'Iba čítanie prehľadov (UI neskôr)',
        ],
    ];

    /**
     * @var array<string, string>
     */
    public array $permissions = [
        'app.manage'   => 'Správa aplikácie',
        'reports.read' => 'Čítanie prehľadov a exportov',
    ];

    /**
     * @var array<string, list<string>>
     */
    public array $matrix = [
        'admin'      => ['app.manage', 'reports.read'],
        'accountant' => ['reports.read'],
    ];
}
