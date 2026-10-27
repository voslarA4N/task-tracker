<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use RuntimeException;

/**
 * Ulozene PDF nezodpoveda ulozenemu sha256.
 */
final class DocumentIntegrityError extends RuntimeException
{
}
