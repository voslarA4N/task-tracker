<?php

declare(strict_types=1);

namespace App\Libraries\Fx;

use RuntimeException;

/**
 * Zdroj odpovedal, ale pre menu a rozsah nepublikuje ziadny kurz (D-12).
 * Dovod NOT_YET_PUBLISHED: bound je v buducnosti, kurz este nikto nezverejnil (WR-01).
 */
final class RateNotFound extends RuntimeException
{
    public const NOT_FOUND         = 'not_found';
    public const NOT_YET_PUBLISHED = 'not_yet_published';

    public function __construct(string $message = '', public readonly string $reason = self::NOT_FOUND)
    {
        parent::__construct($message);
    }
}
