<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Master\BankAccountService;
use App\Libraries\Master\ClientService;
use App\Libraries\Master\EntityService;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Seeder;
use Config\Database;
use RuntimeException;

/**
 * Vyvojove seed data (D-08, D-09). V produkcii odmietne bezat.
 * Hodnoty su ukazkove: .example domeny a verejne testovacie IBAN.
 */
class DevSeeder extends Seeder
{
    public static function assertAllowed(string $environment): void
    {
        if ($environment === 'production') {
            throw new RuntimeException('DevSeeder must never run in production');
        }
    }

    public function run(): void
    {
        self::assertAllowed(ENVIRONMENT);

        $this->db = Database::connect('owner');
        $this->seedWith($this->db);

        CLI::write('DevSeeder: sample entities and client are in place');
    }

    /**
     * Idempotentne vytvori ukazkove subjekty, bankove ucty a klienta.
     *
     * @param BaseConnection<mixed, mixed> $db
     */
    public function seedWith(BaseConnection $db): void
    {
        $audit    = new AuditLogger($db);
        $entities = new EntityService($db, $audit);
        $banks    = new BankAccountService($db, $audit);
        $clients  = new ClientService($db, $audit);

        $sk = $this->entityId($db, 'SK živnosť (ukážka)');

        if ($sk === null) {
            $sk = $entities->create(
                ['name_internal' => 'SK živnosť (ukážka)', 'country' => 'SK', 'currency' => 'EUR', 'active_from' => '2026-01-01', 'active_to' => null],
                $this->version('Ján Ukážkový', 'Hlavná 1', '81101', 'Bratislava', '12345678', null, 'sk', 'sk_non_vat'),
            );
            $banks->add($sk, ['label' => 'Hlavný účet', 'iban' => 'SK3112000000198742637541', 'swift' => null, 'currency' => 'EUR', 'is_default' => true]);
        }

        $pl = $this->entityId($db, 'PL JDG (ukážka)');

        if ($pl === null) {
            $pl = $entities->create(
                ['name_internal' => 'PL JDG (ukážka)', 'country' => 'PL', 'currency' => 'PLN', 'active_from' => '2026-09-01', 'active_to' => null],
                $this->version('Jan Przykładowy', 'Przykładowa 1', '00-001', 'Warszawa', '5260250274', '123456785', 'pl', 'pl_exempt_113_1'),
            );
            $banks->add($pl, ['label' => 'Konto PLN', 'iban' => 'PL61109010140000071219812874', 'swift' => null, 'currency' => 'PLN', 'is_default' => true]);
            $banks->add($pl, ['label' => 'Konto EUR', 'iban' => 'DE89370400440532013000', 'swift' => null, 'currency' => 'EUR', 'is_default' => false]);
        }

        $exists = $db->query('SELECT 1 AS x FROM clients WHERE name = :n:', ['n' => 'All4Net (ukážka)'])->getRow();

        if ($exists === null) {
            $clients->create([
                'name'                => 'All4Net (ukážka)',
                'address_line1'       => 'Hlavná 1',
                'address_line2'       => null,
                'postal_code'         => '81101',
                'city'                => 'Bratislava',
                'country'             => 'SK',
                'identifier_type'     => 'local_id',
                'identifier_value'    => '12345678',
                'tax_id'              => null,
                'vat_id'              => null,
                'invoice_email'       => 'fakturacia@all4net.example',
                'cc_emails'           => [],
                'hourly_rate'         => '27.00',
                'currency'            => 'EUR',
                'doc_language'        => null,
                'due_days'            => null,
                'portal_name'         => 'Taskman',
                'portal_url_template' => 'https://taskman.example/task/{id}',
                'billing_mode'        => 'domestic',
                'counts_toward_limit' => true,
            ]);
        }
    }

    /**
     * @param BaseConnection<mixed, mixed> $db
     */
    private function entityId(BaseConnection $db, string $name): ?int
    {
        $row = $db->query('SELECT id FROM entities WHERE name_internal = :n:', ['n' => $name])->getRow();

        return $row === null ? null : (int) $row->id;
    }

    /**
     * @return array<string, string|null>
     */
    private function version(string $trade, string $street, string $zip, string $city, string $companyId, ?string $regon, string $language, string $regime): array
    {
        return [
            'trade_name'    => $trade,
            'address_line1' => $street,
            'address_line2' => null,
            'postal_code'   => $zip,
            'city'          => $city,
            'company_id'    => $companyId,
            'regon'         => $regon,
            'tax_id'        => null,
            'vat_id'        => null,
            'registry_note' => null,
            'email'         => 'firma@example.test',
            'phone'         => null,
            'doc_language'  => $language,
            'vat_regime'    => $regime,
            'due_days'      => '14',
        ];
    }
}
