<?php

declare(strict_types=1);

namespace App\Libraries\Reporting;

use App\Libraries\Invoicing\Draft;
use App\Libraries\Platform\Money;
use Closure;

/**
 * Predkontrola limitu obratu v nahlade faktury (LIM-04, D-16): nikdy neblokuje a nic neuklada.
 * Suma navrhu sa berie iba zo serverom zostaveneho snapshotu (v mene subjektu), kurz sa tu nehlada (CUR-04).
 */
final class LimitPrecheck
{
    private readonly Closure $limitsFor;

    /**
     * @param (callable(int, string): ?EntityLimits)|null $limitsFor
     */
    public function __construct(?callable $limitsFor = null)
    {
        $this->limitsFor = $limitsFor !== null
            ? Closure::fromCallable($limitsFor)
            : static fn (int $entityId, string $refDate): ?EntityLimits => (new TurnoverCalculator())->forEntityId($entityId, $refDate);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function evaluate(EntityLimits $limits, string $addAmount): ?array
    {
        if ($limits->noLimit) {
            return null;
        }

        $ok       = [];
        $mismatch = false;

        foreach ($limits->statuses as $status) {
            if ($status->error === null) {
                $ok[] = $status;
            } elseif ($status->error === 'currency_mismatch') {
                $mismatch = true;
            }
        }

        if ($ok === [] && $mismatch) {
            return ['state' => 'unavailable', 'reason' => 'currency'];
        }

        $rows = [];
        $crit = false;

        foreach ($ok as $status) {
            $after = Money::of($status->total)->plus(Money::of($addAmount))->toDecimalString();

            if (LimitMath::reachedThresholds($after, $status->limit, $status->thresholds) === []) {
                continue;
            }

            // WR-02: alreadyOver ("Limit uz je prekroceny") iba ked obrat pred fakturou dosiahol 100 % limitu, nie najnizsi prah.
            $level  = LimitMath::level($after, $status->limit, $status->thresholds);
            $crit   = $crit || $level === 'crit';
            $rows[] = [
                'name'          => $status->name,
                'year'          => $status->limitYear,
                'beforePercent' => $status->percent,
                'afterPercent'  => LimitMath::percent($after, $status->limit),
                'before'        => $status->total,
                'add'           => $addAmount,
                'after'         => $after,
                'limit'         => $status->limit,
                'currency'      => $status->currency,
                'level'         => $level,
                'alreadyOver'   => LimitMath::reached($status->total, $status->limit, 100),
            ];
        }

        if ($rows === []) {
            return null;
        }

        return [
            'state'    => 'warning',
            'level'    => $crit ? 'crit' : 'warn',
            'rows'     => $rows,
            'fallback' => $limits->usedYear !== null ? ['refYear' => $limits->refYear, 'usedYear' => $limits->usedYear] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forDraft(Draft $draft): ?array
    {
        $snapshot = $draft->snapshot;

        if ($draft->entityId === null || $snapshot === null || $draft->lineCount === 0) {
            return null;
        }

        if (($snapshot['client']['counts_toward_limit'] ?? false) !== true) {
            return null;
        }

        if ($draft->fx !== null && ! in_array($draft->fx['state'], ['ok', 'manual'], true)) {
            return ['state' => 'unavailable', 'reason' => 'rate'];
        }

        $add    = (string) ($snapshot['fx']['total'] ?? $snapshot['totals']['total']);
        $limits = ($this->limitsFor)($draft->entityId, $draft->deliveryDate);

        if ($limits === null) {
            return null;
        }

        $result = self::evaluate($limits, $add);

        if ($result === null) {
            return null;
        }

        return $result + ['fx' => $draft->fx !== null];
    }
}
