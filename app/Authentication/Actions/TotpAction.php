<?php

declare(strict_types=1);

namespace App\Authentication\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Actions\ConditionalActionInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * Gateway akcia: po spravnom hesle ostava relacia pending a dalsi krok je /2fa.
 * Prihlasenie dokonci iba checkAction() s nonce tejto identity.
 */
final class TotpAction implements ActionInterface, ConditionalActionInterface
{
    public const TYPE = 'totp_gateway';

    /**
     * 2FA je povinna pre kazdeho (fail closed).
     */
    public function appliesTo(User $user): bool
    {
        return true;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function createIdentity(User $user): string
    {
        $identities = model(UserIdentityModel::class);
        $identities->deleteIdentitiesByType($user, self::TYPE);

        return $identities->createCodeIdentity(
            $user,
            ['type' => self::TYPE, 'name' => 'login', 'extra' => ''],
            static fn (): string => bin2hex(random_bytes(16)),
        );
    }

    /**
     * @return RedirectResponse
     */
    public function show()
    {
        return redirect()->to('/2fa');
    }

    /**
     * @return RedirectResponse
     */
    public function handle(IncomingRequest $request)
    {
        return redirect()->to('/2fa');
    }

    /**
     * @return RedirectResponse
     */
    public function verify(IncomingRequest $request)
    {
        return redirect()->to('/2fa');
    }
}
