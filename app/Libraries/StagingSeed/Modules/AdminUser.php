<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed\Modules;

use App\Libraries\Auth\UserProvisioner;
use App\Libraries\StagingSeed\SeedContext;
use App\Libraries\StagingSeed\SeedModule;

/**
 * Stabilny admin bez 2FA (D-04, 2026-10-28): pri prvom prihlaseni uvidi ponuku 2FA.
 * Heslo prichadza zo SeedAdminCredentials, nikdy sa nelogujú. Neposiela ziadny e-mail ani notifikaciu.
 */
final class AdminUser implements SeedModule
{
    public function name(): string
    {
        return 'admin';
    }

    public function run(SeedContext $ctx): void
    {
        $ctx->clock->at('2026-01-01T08:00:00');

        $user = (new UserProvisioner($ctx->db))->create($ctx->credentials->email, $ctx->credentials->password, 'admin', 'sk', $ctx->clock->now());

        $ctx->put('user', 'admin', (int) $user->id);
    }
}
