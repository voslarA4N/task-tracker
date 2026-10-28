<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed\Modules;

use App\Libraries\Auth\TotpService;
use App\Libraries\Auth\UserProvisioner;
use App\Libraries\StagingSeed\SeedContext;
use App\Libraries\StagingSeed\SeedModule;
use RuntimeException;

/**
 * Stabilny admin s TOTP (D-02). Heslo a tajomstvo prichadzaju zo SeedAdminCredentials, nikdy sa nelogujú.
 * Neposiela ziadny e-mail ani notifikaciu.
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
        $now  = $ctx->clock->now()->format('Y-m-d H:i:sP');

        // rovnaky UPDATE ako pri aktivacii 2FA v Auth\TwoFactor, tajomstvo sa uklada sifrovane
        $ctx->db->query(
            'UPDATE user_security SET totp_secret_enc = :enc:, totp_enabled_at = :now:, totp_last_step = NULL, updated_at = :now: WHERE user_id = :u:',
            ['enc' => (new TotpService($ctx->clock, $ctx->db))->encryptSecret($ctx->credentials->totpSecret), 'now' => $now, 'u' => (int) $user->id],
        );

        if ($ctx->db->affectedRows() !== 1) {
            throw new RuntimeException('Seed admin has no user_security row.');
        }

        $ctx->put('user', 'admin', (int) $user->id);
    }
}
