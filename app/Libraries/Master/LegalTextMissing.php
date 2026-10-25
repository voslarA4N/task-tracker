<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use RuntimeException;

/**
 * Pravny profil alebo text sa nenasiel (D-17): nikdy sa nenahradza prazdnym textom.
 */
final class LegalTextMissing extends RuntimeException
{
}
