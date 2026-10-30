<?php

declare(strict_types=1);

namespace App\Libraries\StagingSeed;

/**
 * Pevne vymyslene udaje pre staging seed (D-03): ziadny Faker, ziadna nahoda. Vsetky firmy, osoby a domeny su
 * fiktivne (.example), IBAN su verejne testovacie. Identifikatory maju iba platny tvar, nie realnu existenciu.
 */
final class Catalog
{
    public const IBAN_SK = 'SK3112000000198742637541';
    public const IBAN_CZ = 'CZ6508000000192000145399';
    public const IBAN_PL = 'PL61109010140000071219812874';
    public const IBAN_DE = 'DE89370400440532013000';

    /**
     * Subjekty (vystavovatelia). `version` je tvar EntityService::VERSION_FIELDS.
     *
     * @var array<string, array<string, mixed>>
     */
    public const ENTITIES = [
        'sk' => [
            'country'       => 'SK',
            'currency'      => 'EUR',
            'name_internal' => 'SK živnosť',
            'series_format' => 'RRRRMM###',
            'bank_accounts' => [
                ['label' => 'Hlavný účet EUR', 'iban' => self::IBAN_SK, 'swift' => 'SUBASKBX', 'currency' => 'EUR', 'is_default' => true],
            ],
            'version' => [
                'trade_name'    => 'Marek Vzorový – softvérové služby',
                'address_line1' => 'Priemyselná 14',
                'address_line2' => null,
                'postal_code'   => '82109',
                'city'          => 'Bratislava',
                'company_id'    => '52148730',
                'regon'         => null,
                'tax_id'        => '1086254109',
                'vat_id'        => null,
                'registry_note' => 'Zapísaný v živnostenskom registri OŽP Bratislava, č. 110-52148',
                'email'         => 'marek@vzorovy-sk.example',
                'phone'         => '+421 900 123 456',
                'doc_language'  => 'sk',
                'vat_regime'    => 'sk_non_vat',
                'due_days'      => '14',
            ],
            'later_versions' => [
                ['valid_from' => '2026-07-01', 'changes' => ['address_line1' => 'Mlynské nivy 5', 'postal_code' => '82109']],
            ],
        ],
        'cz' => [
            'country'       => 'CZ',
            'currency'      => 'CZK',
            'name_internal' => 'CZ OSVČ',
            'series_format' => 'RRRR-MM-###',
            'bank_accounts' => [
                ['label' => 'Účet CZK', 'iban' => self::IBAN_CZ, 'swift' => 'GIBACZPX', 'currency' => 'CZK', 'is_default' => true],
            ],
            'version' => [
                'trade_name'    => 'Marek Vzorový – softwarové služby',
                'address_line1' => 'Dlouhá 22',
                'address_line2' => null,
                'postal_code'   => '110 00',
                'city'          => 'Praha',
                'company_id'    => '07654321',
                'regon'         => null,
                'tax_id'        => null,
                'vat_id'        => null,
                'registry_note' => 'Fyzická osoba zapsaná v živnostenském rejstříku',
                'email'         => 'marek@vzorovy-cz.example',
                'phone'         => '+420 601 234 567',
                'doc_language'  => 'cs',
                'vat_regime'    => 'cz_non_vat',
                'due_days'      => '14',
            ],
            'later_versions' => [],
        ],
        'pl' => [
            'country'       => 'PL',
            'currency'      => 'PLN',
            'name_internal' => 'PL JDG',
            'series_format' => 'FV/RRRR/MM/###',
            'bank_accounts' => [
                ['label' => 'Konto PLN', 'iban' => self::IBAN_PL, 'swift' => 'WBKPPLPP', 'currency' => 'PLN', 'is_default' => true],
                ['label' => 'Konto EUR', 'iban' => self::IBAN_DE, 'swift' => 'COBADEFF', 'currency' => 'EUR', 'is_default' => false],
            ],
            'version' => [
                'trade_name'    => 'Marek Wzorowy – usługi programistyczne',
                'address_line1' => 'ul. Przykładowa 8',
                'address_line2' => null,
                'postal_code'   => '00-950',
                'city'          => 'Warszawa',
                'company_id'    => '5260250274',
                'regon'         => '123456785',
                'tax_id'        => '5260250274',
                'vat_id'        => null,
                'registry_note' => 'Wpisany do CEIDG',
                'email'         => 'marek@wzorowy-pl.example',
                'phone'         => '+48 500 123 456',
                'doc_language'  => 'pl',
                'vat_regime'    => 'pl_exempt_113_1',
                'due_days'      => '14',
            ],
            'later_versions' => [],
        ],
    ];

    /**
     * Klienti (identita a fakturacne nastavenie); sprava v case je v Profile.
     *
     * @var array<string, array<string, mixed>>
     */
    public const CLIENTS = [
        'sk_orbit' => [
            'name'          => 'Orbit Systems s. r. o.', 'address_line1' => 'Továrenská 12', 'postal_code' => '81109', 'city' => 'Bratislava',
            'country'       => 'SK', 'identifier_type' => 'local_id', 'identifier_value' => '36421873', 'vat_id' => null,
            'invoice_email' => 'fakturacia@orbit-systems.example', 'billing_mode' => 'domestic', 'doc_language' => null,
            'portal_name'   => 'Taskman', 'portal_url_template' => 'https://taskman.example/task/{id}', 'lang' => 'sk',
        ],
        'sk_alfa' => [
            'name'          => 'Alfa Logistika, s. r. o.', 'address_line1' => 'Kysucká 3', 'postal_code' => '04001', 'city' => 'Košice',
            'country'       => 'SK', 'identifier_type' => 'local_id', 'identifier_value' => '44870219', 'vat_id' => null,
            'invoice_email' => 'uctaren@alfa-logistika.example', 'billing_mode' => 'domestic', 'doc_language' => null,
            'portal_name'   => null, 'portal_url_template' => null, 'lang' => 'sk',
        ],
        'sk_beta' => [
            'name'          => 'Beta Studio s. r. o.', 'address_line1' => 'Hurbanova 41', 'postal_code' => '91101', 'city' => 'Trenčín',
            'country'       => 'SK', 'identifier_type' => 'local_id', 'identifier_value' => '47112358', 'vat_id' => null,
            'invoice_email' => 'faktury@beta-studio.example', 'billing_mode' => 'domestic', 'doc_language' => null,
            'portal_name'   => null, 'portal_url_template' => null, 'lang' => 'sk',
        ],
        'cz_vltava' => [
            'name'          => 'Vltava Digital s.r.o.', 'address_line1' => 'Na Příkopě 18', 'postal_code' => '110 00', 'city' => 'Praha',
            'country'       => 'CZ', 'identifier_type' => 'local_id', 'identifier_value' => '27458913', 'vat_id' => null,
            'invoice_email' => 'fakturace@vltava-digital.example', 'billing_mode' => 'domestic', 'doc_language' => null,
            'portal_name'   => null, 'portal_url_template' => null, 'lang' => 'cs',
        ],
        'cz_hana' => [
            'name'          => 'Hanácké strojírny a.s.', 'address_line1' => 'Jeremenkova 90', 'postal_code' => '779 00', 'city' => 'Olomouc',
            'country'       => 'CZ', 'identifier_type' => 'local_id', 'identifier_value' => '25311972', 'vat_id' => null,
            'invoice_email' => 'ucetni@hanacke-strojirny.example', 'billing_mode' => 'domestic', 'doc_language' => null,
            'portal_name'   => null, 'portal_url_template' => null, 'lang' => 'cs',
        ],
        'pl_wisla' => [
            'name'          => 'Wisła Logistyka Sp. z o.o.', 'address_line1' => 'ul. Długa 27', 'postal_code' => '31-147', 'city' => 'Kraków',
            'country'       => 'PL', 'identifier_type' => 'local_id', 'identifier_value' => '6762013917', 'vat_id' => null,
            'invoice_email' => 'faktury@wisla-logistyka.example', 'billing_mode' => 'domestic', 'doc_language' => null,
            'portal_name'   => null, 'portal_url_template' => null, 'lang' => 'pl',
        ],
        'pl_rhein' => [
            'name'          => 'Rheinwerk Software GmbH', 'address_line1' => 'Hohenzollernring 52', 'postal_code' => '50672', 'city' => 'Köln',
            'country'       => 'DE', 'identifier_type' => 'eu_vat', 'identifier_value' => 'DE811234567', 'vat_id' => 'DE811234567',
            'invoice_email' => 'buchhaltung@rheinwerk-software.example', 'billing_mode' => 'eu_b2b', 'doc_language' => 'en',
            'portal_name'   => null, 'portal_url_template' => null, 'lang' => 'en',
        ],
        'pl_alpen' => [
            'name'          => 'Alpenmechanik AG', 'address_line1' => 'Bahnhofstrasse 70', 'postal_code' => '8001', 'city' => 'Zürich',
            'country'       => 'CH', 'identifier_type' => 'other', 'identifier_value' => 'CHE-123.456.789', 'vat_id' => null,
            'invoice_email' => 'accounting@alpenmechanik.example', 'billing_mode' => 'non_eu', 'doc_language' => 'en',
            'portal_name'   => null, 'portal_url_template' => null, 'lang' => 'en', 'currency' => 'EUR',
        ],
    ];

    /**
     * Cinnosti (podstatne mena, ktore berú genitiv) a predmety v genitive podla jazyka.
     *
     * @var array<string, array{actions: list<string>, objects: list<string>}>
     */
    public const TITLES = [
        'sk' => [
            'actions' => ['Oprava', 'Úprava', 'Implementácia', 'Analýza', 'Nasadenie', 'Refaktoring', 'Optimalizácia', 'Testovanie', 'Konzultácia', 'Migrácia', 'Dokumentácia', 'Integrácia'],
            'objects' => ['prihlasovania používateľov', 'zoznamu objednávok', 'exportu do XLSX', 'REST API pre mobilnú aplikáciu', 'importu produktov', 'mesačného reportu tržieb', 'e-mailových notifikácií', 'vyhľadávania v katalógu', 'platobnej brány', 'číselníkov', 'dashboardu', 'synchronizácie skladu', 'migrácie databázy', 'fakturačného modulu'],
        ],
        'cs' => [
            'actions' => ['Oprava', 'Úprava', 'Implementace', 'Analýza', 'Nasazení', 'Refaktoring', 'Optimalizace', 'Testování', 'Konzultace', 'Migrace', 'Dokumentace', 'Integrace'],
            'objects' => ['přihlašování uživatelů', 'seznamu objednávek', 'exportu do XLSX', 'REST API pro mobilní aplikaci', 'importu produktů', 'měsíčního reportu tržeb', 'e-mailových notifikací', 'vyhledávání v katalogu', 'platební brány', 'číselníků', 'dashboardu', 'synchronizace skladu', 'migrace databáze', 'fakturačního modulu'],
        ],
        'pl' => [
            'actions' => ['Poprawa', 'Zmiana', 'Implementacja', 'Analiza', 'Wdrożenie', 'Refaktoryzacja', 'Optymalizacja', 'Testowanie', 'Konsultacja', 'Migracja', 'Dokumentacja', 'Integracja'],
            'objects' => ['logowania użytkowników', 'listy zamówień', 'eksportu do XLSX', 'REST API dla aplikacji mobilnej', 'importu produktów', 'raportu miesięcznych przychodów', 'powiadomień e-mail', 'wyszukiwarki w katalogu', 'bramki płatności', 'słowników', 'dashboardu', 'synchronizacji magazynu', 'migracji bazy danych', 'modułu fakturowania'],
        ],
        'en' => [
            'actions' => ['Fixing', 'Refining', 'Implementing', 'Analysing', 'Deploying', 'Refactoring', 'Optimising', 'Testing', 'Consulting on', 'Migrating', 'Documenting', 'Integrating'],
            'objects' => ['user login', 'order list', 'XLSX export', 'REST API for the mobile app', 'product import', 'monthly revenue report', 'e-mail notifications', 'catalogue search', 'payment gateway', 'lookup tables', 'dashboard', 'stock synchronisation', 'database migration', 'invoicing module'],
        ],
    ];
}
