<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

use RuntimeException;

/**
 * Seed odmietol bezat. Sprava nikdy nenesie tajne hodnoty, iba kod dovodu.
 */
final class SeedRefused extends RuntimeException
{
    public const FLAG_OFF        = 'flag_off';
    public const PRODUCTION_HOST = 'production_host';
    public const NOT_PRISTINE    = 'not_pristine';
    public const CREDENTIALS     = 'credentials';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct('Staging seed refused: ' . $reason . ($detail === '' ? '' : ' (' . $detail . ')'));
    }
}
