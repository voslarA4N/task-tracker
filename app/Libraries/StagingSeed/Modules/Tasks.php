<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed\Modules;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\StagingSeed\SeedContext;
use App\Libraries\StagingSeed\SeedModule;
use App\Libraries\Work\AllocationService;
use App\Libraries\Work\TaskInput;
use App\Libraries\Work\TaskService;
use RuntimeException;

/**
 * Ulohy a ich casti v chronologickom poradi udalosti (vytvorenie, cast, zapracovanie) cez TaskService a AllocationService.
 * Posledna cast hotovej ulohy vznika cez markDone, ostatne cez AllocationService::add.
 */
final class Tasks implements SeedModule
{
    public function name(): string
    {
        return 'tasks';
    }

    public function run(SeedContext $ctx): void
    {
        $audit       = new AuditLogger($ctx->db);
        $tasks       = new TaskService($ctx->db, $audit);
        $allocations = new AllocationService($ctx->db, $audit);

        $rates = [];

        foreach ($ctx->plan->clients as $c) {
            $rates[$c['key']] = (string) $c['hourly_rate'];
        }

        $events = [];
        $order  = 0;

        foreach ($ctx->plan->tasks as $t) {
            $events[] = ['at' => $t['created_at'], 'order' => $order++, 'type' => 'create', 'task' => $t, 'index' => 0];
            $last     = count($t['parts']) - 1;

            foreach ($t['parts'] as $i => $p) {
                $events[] = ['at' => $p['at'], 'order' => $order++, 'type' => $t['done_at'] !== null && $i === $last ? 'done' : 'part', 'task' => $t, 'index' => $i];
            }
        }

        usort($events, static fn (array $a, array $b): int => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        foreach ($events as $ev) {
            $t = $ev['task'];
            $ctx->clock->at($ev['at']);

            if ($ev['type'] === 'create') {
                $values = TaskInput::normalize([
                    'client_id' => (string) $ctx->id('client', $t['client']),
                    'url'       => $t['ticket'] ?? '',
                    'title'     => $t['title'],
                    'hours'     => $t['hours'],
                    'rate'      => $t['rate'] ?? $rates[$t['client']],
                    'note'      => '',
                ]);
                $ctx->put('task', $t['key'], $tasks->create($values));

                continue;
            }

            $taskId = $ctx->id('task', $t['key']);
            $part   = $t['parts'][$ev['index']];

            if ($ev['type'] === 'done') {
                $partId = $tasks->markDone($taskId, $part['month'], $part['hours']);
            } else {
                $partId = $allocations->add($taskId, ['hours' => $part['hours'], 'billing_month' => $part['month']]);
            }

            if ($partId === null) {
                throw new RuntimeException('Seed part was not created for task ' . $t['key']);
            }

            $ctx->put('part', $t['key'] . '#' . $ev['index'], $partId);
        }
    }
}
