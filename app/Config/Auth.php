<?php

declare(strict_types=1);

namespace Config;

use App\Authentication\Actions\TotpAction;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Passwords\CompositionValidator;
use CodeIgniter\Shield\Authentication\Passwords\DictionaryValidator;
use CodeIgniter\Shield\Authentication\Passwords\NothingPersonalValidator;
use CodeIgniter\Shield\Authentication\Passwords\ValidatorInterface;
use CodeIgniter\Shield\Config\Auth as ShieldAuth;

/**
 * Shield: iba heslo + povinna TOTP akcia, bez registracie, magic linkov a remember-me.
 */
class Auth extends ShieldAuth
{
    /**
     * @var array<string, class-string<ActionInterface>|null>
     */
    public array $actions = [
        'register' => null,
        'login'    => TotpAction::class,
    ];

    public bool $allowRegistration    = false;
    public bool $allowMagicLinkLogins = false;
    public bool $recordActiveDate     = false;

    /**
     * Remember-me by obisiel 2FA (Pitfall 1), preto je vypnute.
     *
     * @var array<string, bool|int|string>
     */
    public array $sessionConfig = [
        'field'              => 'user',
        'allowRemembering'   => false,
        'rememberCookieName' => 'remember',
        'rememberLength'     => 30 * DAY,
    ];

    public int $minimumPasswordLength = 12;
    public string $hashAlgorithm      = PASSWORD_ARGON2ID;
    public int $hashMemoryCost        = 19456;
    public int $hashTimeCost          = 2;
    public int $hashThreads           = 1;

    /**
     * Bez PwnedValidator: ziadne volania tretej strany.
     *
     * @var list<class-string<ValidatorInterface>>
     */
    public array $passwordValidators = [
        CompositionValidator::class,
        NothingPersonalValidator::class,
        DictionaryValidator::class,
    ];

    /**
     * @var array<string, string>
     */
    public array $redirects = [
        'register'          => '/',
        'login'             => '/',
        'logout'            => '/login',
        'force_reset'       => '/',
        'permission_denied' => '/',
        'group_denied'      => '/',
    ];
}
