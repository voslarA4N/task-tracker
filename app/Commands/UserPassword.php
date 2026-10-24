<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Auth\AccountRecovery;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

/**
 * Nudzove nastavenie hesla zo servera (D-14: staging nema e-mail). Heslo sa zadava interaktivne.
 *
 * @internal
 */
final class UserPassword extends BaseCommand
{
    use ReadsSecretTrait;

    protected $group       = 'Auth';
    protected $name        = 'user:password';
    protected $description = 'Nastaví nové heslo používateľovi, heslo sa zadáva interaktívne.';
    protected $usage       = 'user:password <email>';
    protected $arguments   = ['email' => 'E-mail používateľa'];

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

        $password = $this->readSecret('Nové heslo: ');
        $confirm  = $this->readSecret('Nové heslo znova: ');

        if ($password !== $confirm) {
            CLI::error('Heslá sa nezhodujú.');

            return EXIT_ERROR;
        }

        try {
            (new AccountRecovery())->setPassword($user, $password, 'cli', service('clock')->now());
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Heslo zmenené. Dôveryhodné zariadenia boli odvolané. Pri prihlásení sa vyžaduje aj 2FA.', 'green');

        return EXIT_SUCCESS;
    }
}
