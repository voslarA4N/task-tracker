<?php

declare(strict_types=1);

/**
 * Samostatny proces vystavujuci dobropis cez spojenie `tests` (app_rw) pre test sueboh (plan 09-05).
 *
 * CLI: php tests/_support/credit_note_worker.php <workerIndex> --command <json>
 * JSON: {original, issue, reason, type, token, key}
 * Vystup: JSON {"worker": n, "numbers": [...], "errors": [...], "rejected": [{"reason": ..., "credit_note_id": ...}]}
 */

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteCommand;
use App\Libraries\Invoicing\CreditNoteIssuer;
use App\Libraries\Invoicing\CreditNoteRejected;
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

Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));

$db = Database::connect('tests', false);

// Cakanie na spolocny start: rodic drzi exkluzivny zamok 777 a uvolni ho, ked oba workery cakaju.
$db->query('SELECT pg_advisory_lock_shared(777)');

$numbers  = [];
$errors   = [];
$rejected = [];

if ($mode === '--command') {
    /** @var array{original: int, issue: string, reason: string, type: string, token: string, key: string} $c */
    $c      = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    $issuer = new CreditNoteIssuer($db, new AuditLogger($db), new RendererRegistry(['cn-1' => new FakePdfRenderer('cn-1')]));

    try {
        $numbers[] = $issuer->issue(new CreditNoteCommand($c['original'], $c['issue'], $c['reason'], CorrectionType::from($c['type']), $c['token'], $c['key'], false, null))->number;
    } catch (CreditNoteRejected $e) {
        $rejected[] = ['reason' => $e->reason, 'credit_note_id' => $e->creditNoteId];
    } catch (Throwable $e) {
        $errors[] = $e::class . ': ' . $e->getMessage();
    }
}

echo json_encode(['worker' => (int) $index, 'numbers' => $numbers, 'errors' => $errors, 'rejected' => $rejected], JSON_THROW_ON_ERROR);

exit(0);
