<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Reporting\LimitSelector;
use App\Libraries\Reporting\TurnoverCalculator;

/**
 * Nastavenia, Limity obratu: cerpanie za aktivne subjekty a zoznam limitov (LIM-01, LIM-02, LIM-05).
 * Cisla pocita vylucne TurnoverCalculator (D-08), tu sa iba zbieraju data pre view.
 */
final class Limits extends BaseController
{
    public function index(): string
    {
        $today  = service('clock')->today();
        $limits = (new LimitSelector())->all();

        $activeFrom = [];

        foreach ((new EntityDirectory())->listAll($today) as $entity) {
            $activeFrom[$entity['id']] = $entity['active_from'];
        }

        return view('settings/limits/index', [
            'entities'   => (new TurnoverCalculator())->forActiveEntities($today),
            'limits'     => $limits,
            'hasSeed'    => array_filter($limits, static fn (array $l): bool => $l['is_seed']) !== [],
            'activeFrom' => $activeFrom,
            'locale'     => $this->request->getLocale(),
            'today'      => $today,
        ]);
    }
}
