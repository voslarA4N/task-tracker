<?php

declare(strict_types=1);

/**
 * Samostatny proces vystavujuci faktury cez spojenie `tests` (app_rw) pre test sueboh (plan 05-06).
 *
 * CLI: php tests/_support/issue_worker.php <workerIndex> <clientIdsCsv> <month> <issueDate> <entityId>
 * Vystup: JSON {"worker": n, "numbers": [...], "errors": [...]}
 */

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\InvoiceCalculator;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Invoicing\InvoiceParts;
use App\Libraries\Invoicing\IssueCommand;
use App\Libraries\Invoicing\SnapshotBuilder;
use App\Libraries\Platform\FrozenClock;
use Config\Database;
use Config\Services;
use Tests\Support\FakePdfRenderer;

chdir(dirname(__DIR__, 2));
putenv('CI_ENVIRONMENT=testing');
$_SERVER['CI_ENVIRONMENT'] = 'testing';

require 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

[, $index, $clientCsv, $month, $issueDate, $entityId] = ($_SERVER['argv'] ?? []) + [null, '0', '', '', '', '0'];

Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));

$db = Database::connect('tests', false);

// Cakanie na spolocny start: rodic drzi exkluzivny zamok 777 a uvolni ho, ked oba workery cakaju.
$db->query('SELECT pg_advisory_lock_shared(777)');

$numbers = [];
$errors  = [];
$issuer  = new InvoiceIssuer($db, new AuditLogger($db), new FakePdfRenderer());

foreach (array_filter(explode(',', (string) $clientCsv), static fn (string $v): bool => $v !== '') as $clientId) {
    $clientId = (int) $clientId;

    try {
        $unlocked  = (new InvoiceParts($db))->unlocked($clientId, (string) $month, null);
        $clientRow = $db->query('SELECT * FROM clients WHERE id = :c:', ['c' => $clientId])->getRowArray();
        $entityRow = $db->query('SELECT * FROM entities WHERE id = :e:', ['e' => (int) $entityId])->getRowArray();
        $snapshot  = (new SnapshotBuilder($db))->build($clientRow, $entityRow, $unlocked, (string) $month, (string) $issueDate, '2026-10-31', '2026-11-10', null);
        $token     = InvoiceCalculator::previewToken($snapshot);
        $partIds   = array_map(static fn (array $p): int => (int) $p['allocation_id'], $unlocked);
        sort($partIds);

        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h    = bin2hex($b);
        $key  = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);

        $issued    = $issuer->issue(new IssueCommand($clientId, (string) $month, (int) $entityId, (string) $issueDate, '2026-10-31', '2026-11-10', $partIds, $token, $key, false, null));
        $numbers[] = $issued->number;
    } catch (Throwable $e) {
        $errors[] = $clientId . ': ' . $e::class . ': ' . $e->getMessage();
    }
}

echo json_encode(['worker' => (int) $index, 'numbers' => $numbers, 'errors' => $errors], JSON_THROW_ON_ERROR);

exit(0);
