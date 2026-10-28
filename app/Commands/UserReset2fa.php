<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Auth\AccountRecovery;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Jediny sposob obnovy 2FA (D-12): iba zo servera, nikdy cez web ani e-mail.
 *
 * @internal
 */
final class UserReset2fa extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'user:reset-2fa';
    protected $description = 'Zruší 2FA, záložné kódy a dôveryhodné zariadenia používateľa.';
    protected $usage       = 'user:reset-2fa <email> [--yes]';
    protected $arguments   = ['email' => 'E-mail používateľa'];
    protected $options     = ['--yes' => 'Bez potvrdzovacej otázky'];

    public function run(array $params): int
    {
        $email = $params[0] ?? null;

        if ($email === null) {
            CLI::error('Použitie: ' . $this->usage);

            return EXIT_ERROR;
        }

        $user = model(UserModel::class)->findByCredentials(['email' => mb_strtolower(trim($email))]);

        if (! $user instanceof User) {
            CLI::error('Používateľ ' . $email . ' neexistuje.');

            return EXIT_ERROR;
        }

        $confirmed = array_key_exists('yes', $params) || CLI::getOption('yes') !== null;

        if (! $confirmed && CLI::prompt('Naozaj zmazať 2FA, záložné kódy a dôveryhodné zariadenia pre ' . $user->email . '?', ['nie', 'ano']) !== 'ano') {
            CLI::write('Zrušené.');

            return EXIT_ERROR;
        }

        (new AccountRecovery())->resetTwoFactor($user, service('clock')->now());

        CLI::write('2FA bola zrušená. Pri ďalšom prihlásení sa zobrazí ponuka nastavenia 2FA.', 'green');

        return EXIT_SUCCESS;
    }
}
