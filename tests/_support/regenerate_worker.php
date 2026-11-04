<?php

declare(strict_types=1);

/**
 * Samostatny proces pregenerovavajuci doklad cez spojenie `tests` (app_rw) pre test sueboh (plan 11.1-04).
 *
 * CLI: php tests/_support/regenerate_worker.php <workerIndex> --command <json>
 * JSON: {invoice, kind, expected}
 * Vystup: JSON {"worker": n, "versions": [...], "errors": [...], "rejected": [{"reason": ...}]}
 */

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\DocumentRegenerator;
use App\Libraries\Invoicing\InvoiceRegenWindows;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\RegenerateRejected;
use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Platform\FrozenClock;
use Config\Database;
use Config\Services;
use Tests\Support\FakePdfRenderer;

chdir(dirname(__DIR__, 2));
putenv('CI_ENVIRONMENT=testing');
$_SERVER['CI_ENVIRONMENT'] = 'testing';

require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

[, $index, $mode, $json] = ($_SERVER['argv'] ?? []) + [null, '0', '', '{}'];

Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));

$db = Database::connect('tests', false);

// Cakanie na spolocny start: rodic drzi exkluzivny zamok 777 a uvolni ho, ked oba workery cakaju.
$db->query('SELECT pg_advisory_lock_shared(777)');

$versions = [];
$errors   = [];
$rejected = [];

if ($mode === '--command') {
    /** @var array{invoice: int, kind: string, expected: int} $c */
    $c           = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    $regenerator = new DocumentRegenerator(
        $db,
        new AuditLogger($db),
        new RendererRegistry(['inv-2' => new FakePdfRenderer('inv-2'), 'rep-2' => new FakePdfRenderer('rep-2')]),
        new DocumentStore($db),
        new InvoiceRegenWindows($db),
    );

    try {
        $versions[] = $regenerator->regenerate($c['invoice'], $c['kind'], $c['expected'])->version;
    } catch (RegenerateRejected $e) {
        $rejected[] = ['reason' => $e->reason];
    } catch (Throwable $e) {
        $errors[] = $e::class . ': ' . $e->getMessage();
    }
}

echo json_encode(['worker' => (int) $index, 'versions' => $versions, 'errors' => $errors, 'rejected' => $rejected], JSON_THROW_ON_ERROR);

exit(0);
