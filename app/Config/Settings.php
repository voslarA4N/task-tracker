<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Settings\Config\Settings as BaseSettings;

/**
 * Iba array handler: setting() nerobi DB citania pri kazdej poziadavke,
 * hodnoty sa beru z Config tried (Shield cita setting('Auth.*')).
 */
class Settings extends BaseSettings
{
    /**
     * @var list<string>
     */
    public $handlers = ['array'];
}
