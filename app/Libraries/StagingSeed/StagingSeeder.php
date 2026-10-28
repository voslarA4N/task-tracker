<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

use App\Libraries\Platform\Clock;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\App;
use Config\Services;
use Config\Staging;

/**
 * Orchestrator staging seedu (D-01, D-03, D-04): poistky, plan, hodiny a moduly v poradi.
 * Novy modul (platby, kurzy, dobropisy, limity) sa prida na koniec MODULES, postup je v docs/ops/server.md.
 */
final class StagingSeeder
{
    /**
     * @var list<class-string<SeedModule>>
     */
    public const MODULES = [
        Modules\AdminUser::class,
        Modules\MasterData::class,
        Modules\Tasks::class,
        Modules\Invoices::class,
    ];

    /**
     * Tabulky, z ktorych sa pocty vypisuju po behu.
     */
    private const COUNTED = ['entities', 'clients', 'tasks', 'billing_allocations', 'invoices', 'invoice_documents'];

    private readonly Closure $log;

    /**
     * @param BaseConnection<mixed, mixed> $db
     */
    public function __construct(private readonly BaseConnection $db, private readonly ?SeedAdminCredentials $credentials = null, ?callable $log = null)
    {
        $this->log = $log === null ? static function (string $message): void {
        } : Closure::fromCallable($log);
    }

    /**
     * @return array{counts: array<string, int>, modules: array<string, int>, millis: int, notes: array<string, mixed>}
     *
     * @throws SeedRefused
     */
    public function run(Profile $profile): array
    {
        $cfg = config(Staging::class);
        $app = config(App::class);

        SeedGuard::check($cfg->seed, $app->baseURL, SeedGuard::countsFor($this->db));

        $credentials = $this->credentials ?? SeedAdminCredentials::load($cfg->adminFile, ENVIRONMENT);
        $original    = service('clock');
        $real        = new Clock();
        $started     = (int) $real->now()->format('Uv');
        $plan        = HistoryPlanner::build($profile, $original->today());
        $clock       = new SeedClock(HistoryPlanner::START . 'T08:00:00');
        $ctx         = new SeedContext($this->db, $clock, $plan, $profile, $credentials, $this->log);
        $timings     = [];

        // hodiny sa menia len pocas behu, povodne sa vzdy vratia
        Services::injectMock('clock', $clock);

        try {
            foreach (self::MODULES as $class) {
                $module = new $class();
                $from   = (int) $real->now()->format('Uv');
                $ctx->log('Modul ' . $module->name() . '...');
                $module->run($ctx);
                $timings[$module->name()] = (int) $real->now()->format('Uv') - $from;
            }
        } finally {
            Services::injectMock('clock', $original);
        }

        $counts = [];

        foreach (self::COUNTED as $table) {
            $counts[$table] = (int) $this->db->query('SELECT count(*) AS c FROM ' . $this->db->escapeIdentifiers($table))->getRow()->c;
        }

        return ['counts' => $counts, 'modules' => $timings, 'millis' => (int) $real->now()->format('Uv') - $started, 'notes' => $ctx->notes];
    }
}
