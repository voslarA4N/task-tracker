<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

/**
 * Jeden krok seedu (D-04). Modul cita HistoryPlan, zapisuje len cez aplikacne sluzby (nikdy surove INSERT
 * do biznis tabuliek) a pred kazdou udalostou nastavi $ctx->clock->at(...).
 */
interface SeedModule
{
    public function name(): string;

    public function run(SeedContext $ctx): void;
}
