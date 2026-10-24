<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Auth\SecurityNotifier;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Overi dorucenie bezpecnostneho upozornenia (dev Mailpit, staging log).
 *
 * @internal
 */
final class NotifyTest extends BaseCommand
{
    protected $group       = 'Dev';
    protected $name        = 'notify:test';
    protected $description = 'Odošle testovacie bezpečnostné upozornenie.';
    protected $usage       = 'notify:test <email> [kind]';
    protected $arguments   = [
        'email' => 'Adresa príjemcu',
        'kind'  => 'Druh upozornenia (predvolene account_locked)',
    ];

    public function run(array $params): int
    {
        $to   = $params[0] ?? null;
        $kind = $params[1] ?? SecurityNotifier::ACCOUNT_LOCKED;

        if ($to === null) {
            CLI::error('Použitie: ' . $this->usage);

            return EXIT_ERROR;
        }

        (new SecurityNotifier())->notify($kind, $to, 'sk', [
            'at'      => service('clock')->now(),
            'ip'      => '127.0.0.1',
            'device'  => 'CLI',
            'minutes' => 15,
        ]);

        CLI::write('Transport: ' . config('Email')->transport . ', druh: ' . $kind, 'green');

        return EXIT_SUCCESS;
    }
}
