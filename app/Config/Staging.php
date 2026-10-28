<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Staging seed (D-01, D-02). Env kluce: staging.seed, staging.adminFile.
 * Predvolene vypnute: iba app.env stagingu nastavuje `staging.seed = true`.
 */
class Staging extends BaseConfig
{
    /**
     * Povolenie `spark staging:seed`. V produkcii nesmie byt nikdy true.
     */
    public bool $seed = false;

    /**
     * Subor s prihlasovacimi udajmi seed admina (mount len do jednorazoveho seed kontajnera).
     */
    public string $adminFile = '/run/secrets/seed_admin.env';
}
