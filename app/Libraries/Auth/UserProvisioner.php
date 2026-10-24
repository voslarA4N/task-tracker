<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Authentication\Passwords;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Auth;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Jediny sposob vzniku uctu: pouzivatel + skupina + riadok user_security v jednej transakcii.
 */
final class UserProvisioner
{
    private const GROUPS = ['admin', 'accountant'];

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function create(string $email, string $password, string $group, string $locale, DateTimeImmutable $now): User
    {
        $email = mb_strtolower(trim($email));

        if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Neplatný e-mail.');
        }
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException('Neplatná skupina, povolené: ' . implode(', ', self::GROUPS) . '.');
        }

        $db = $this->db ?? db_connect();

        $exists = $db->table('auth_identities')->where('type', 'email_password')->where('secret', $email)->countAllResults() > 0;
        if ($exists) {
            throw new DomainException('Používateľ s e-mailom ' . $email . ' už existuje.');
        }

        $check = (new Passwords(config(Auth::class)))->check($password, new User(['email' => $email, 'password' => $password]));
        if (! $check->isOK()) {
            throw new InvalidArgumentException($check->reason());
        }

        $users = model(UserModel::class);

        $db->transException(true)->transStart();

        try {
            $users->save(new User(['email' => $email, 'password' => $password, 'active' => true]));
            $user = $users->findById($users->getInsertID());
            if (! $user instanceof User) {
                throw new DomainException('Používateľa sa nepodarilo uložiť.');
            }
            $user->addGroup($group);

            $stamp = $now->format(DATE_ATOM);
            $db->query(
                'INSERT INTO user_security (user_id, locale, created_at, updated_at) VALUES (?, ?, ?, ?)',
                [$user->id, $locale, $stamp, $stamp],
            );
            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        } finally {
            $db->transException(false);
        }

        return $user;
    }
}
