<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Auth\AuditLog;
use App\Libraries\Auth\AuthEvent;
use App\Libraries\Auth\UserProvisioner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;
use InvalidArgumentException;

/**
 * Jediny sposob vzniku uctu (D-09): heslo sa nikdy neberie z argumentov.
 *
 * @internal
 */
final class UserCreate extends BaseCommand
{
    use ReadsSecretTrait;

    protected $group       = 'Auth';
    protected $name        = 'user:create';
    protected $description = 'Vytvorí používateľa (admin alebo accountant), heslo sa zadáva interaktívne.';
    protected $usage       = 'user:create <email> --admin|--accountant [--locale=sk]';
    protected $arguments   = ['email' => 'E-mail používateľa'];
    protected $options     = [
        '--admin'      => 'Vlastník, plný prístup',
        '--accountant' => 'Účtovníčka, iba čítanie',
        '--locale'     => 'Jazyk rozhrania (predvolene sk)',
    ];

    public function run(array $params): int
    {
        $email      = $params[0] ?? null;
        $admin      = array_key_exists('admin', $params)      || CLI::getOption('admin') !== null;
        $accountant = array_key_exists('accountant', $params) || CLI::getOption('accountant') !== null;

        if ($email === null || $admin === $accountant) {
            CLI::error('Použitie: ' . $this->usage . ' (presne jeden z prepínačov --admin / --accountant).');

            return EXIT_ERROR;
        }

        $locale = CLI::getOption('locale');
        $locale = is_string($locale) && $locale !== '' ? $locale : 'sk';
        $group  = $admin ? 'admin' : 'accountant';

        $password = $this->readSecret('Heslo: ');
        $confirm  = $this->readSecret('Heslo znova: ');

        if ($password !== $confirm) {
            CLI::error('Heslá sa nezhodujú.');

            return EXIT_ERROR;
        }

        try {
            $user = (new UserProvisioner())->create($email, $password, $group, $locale, service('clock')->now());
        } catch (DomainException|InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        (new AuditLog())->record(AuthEvent::UserCreatedCli, true, (int) $user->id, null, 'group=' . $group);

        CLI::write('Používateľ ' . $user->email . ' vytvorený v skupine ' . $group . '. 2FA sa nastaví pri prvom prihlásení.', 'green');

        return EXIT_SUCCESS;
    }
}
