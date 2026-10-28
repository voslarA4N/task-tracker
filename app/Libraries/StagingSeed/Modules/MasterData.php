<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed\Modules;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\BankAccountService;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\SeriesService;
use App\Libraries\StagingSeed\SeedContext;
use App\Libraries\StagingSeed\SeedModule;

/**
 * Subjekty, bankove ucty, rady cisel a klienti (D-03). Pravne texty sa nemenia: su z migracii ("ako nasadene").
 */
final class MasterData implements SeedModule
{
    public function name(): string
    {
        return 'master';
    }

    public function run(SeedContext $ctx): void
    {
        $audit    = new AuditLogger($ctx->db);
        $entities = new EntityService($ctx->db, $audit);
        $banks    = new BankAccountService($ctx->db, $audit);
        $series   = new SeriesService($ctx->db, $audit);
        $clients  = new ClientService($ctx->db, $audit);

        foreach ($ctx->plan->entities as $e) {
            $ctx->clock->at($e['active_from'] . 'T08:00:00');

            $id = $entities->create(
                ['name_internal' => $e['name_internal'], 'country' => $e['country'], 'currency' => $e['currency'], 'active_from' => $e['active_from'], 'active_to' => null],
                $e['version'],
            );
            $ctx->put('entity', $e['key'], $id);

            foreach ($e['bank_accounts'] as $account) {
                $banks->add($id, $account);
            }

            $series->update($id, 'invoice', $e['series_format'], '1');
        }

        foreach ($ctx->plan->clients as $c) {
            $ctx->clock->at($c['first_month'] . '-01T08:00:00');

            $id = $clients->create([
                'name'                => $c['name'],
                'address_line1'       => $c['address_line1'],
                'address_line2'       => null,
                'postal_code'         => $c['postal_code'],
                'city'                => $c['city'],
                'country'             => $c['country'],
                'identifier_type'     => $c['identifier_type'],
                'identifier_value'    => $c['identifier_value'],
                'tax_id'              => null,
                'vat_id'              => $c['vat_id'],
                'invoice_email'       => $c['invoice_email'],
                'cc_emails'           => [],
                'hourly_rate'         => $c['hourly_rate'],
                'currency'            => $c['currency'],
                'doc_language'        => $c['doc_language'],
                'due_days'            => $c['due_days'],
                'portal_name'         => $c['portal_name'],
                'portal_url_template' => $c['portal_url_template'],
                'billing_mode'        => $c['billing_mode'],
                'counts_toward_limit' => true,
            ]);
            $ctx->put('client', $c['key'], $id);
        }

        // neskorsie verzie udajov subjektu (napr. zmena adresy), uz ked ich cas "dnes" dosiahol
        foreach ($ctx->plan->entities as $e) {
            foreach ($e['later_versions'] as $later) {
                $ctx->clock->at($later['valid_from'] . 'T08:00:00');
                $entities->newVersion($ctx->id('entity', $e['key']), $later['valid_from'], array_replace($e['version'], $later['changes']));
            }
        }
    }
}
