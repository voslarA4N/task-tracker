<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Pravne profily a texty (SET-02, D-16, D-17).
 *
 * Zneni aj mapovanie kodov su PREDBEZNE (RESEARCH A1, A2): potvrdi ich uctovnicka
 * (riziko R1, otazky v .planning/research/SUMMARY.md). Oprava ide novou migraciou
 * alebo cez UI v Phase 6 (SET-01), nikdy editom tejto migracie.
 */
final class CreateLegalProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE legal_profiles (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                country char(2) NOT NULL CHECK (country IN ('SK','PL','CZ')),
                vat_regime text NOT NULL CHECK (vat_regime IN ('sk_non_vat','pl_exempt_113_1','pl_exempt_113_9','cz_non_vat')),
                billing_mode text NULL CHECK (billing_mode IN ('domestic','eu_b2b','non_eu')),
                exemption_code text NULL CHECK (exemption_code ~ '^[A-Z0-9_]{3,30}$'),
                updated_at timestamptz NOT NULL,
                CONSTRAINT legal_profiles_key UNIQUE NULLS NOT DISTINCT (country, vat_regime, billing_mode)
            )
            SQL);
        $this->db->query(<<<'SQL'
            CREATE TABLE legal_texts (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                country char(2) NOT NULL CHECK (country IN ('SK','PL','CZ')),
                vat_regime text NOT NULL CHECK (vat_regime IN ('sk_non_vat','pl_exempt_113_1','pl_exempt_113_9','cz_non_vat')),
                billing_mode text NULL CHECK (billing_mode IN ('domestic','eu_b2b','non_eu')),
                language text NOT NULL CHECK (language IN ('sk','pl','cs','en')),
                key text NOT NULL CHECK (key ~ '^[a-z_]{3,40}$'),
                body text NOT NULL CHECK (char_length(body) BETWEEN 1 AND 1000),
                provisional boolean NOT NULL DEFAULT true,
                updated_at timestamptz NOT NULL,
                CONSTRAINT legal_texts_key UNIQUE NULLS NOT DISTINCT (country, vat_regime, billing_mode, language, key)
            )
            SQL);
        $this->db->query("COMMENT ON TABLE legal_profiles IS 'Exemption code per country, VAT regime and billing mode; read-only for app_rw (D-16)'");
        $this->db->query("COMMENT ON TABLE legal_texts IS 'Provisional legal wording per profile and language; read-only for app_rw (D-17)'");

        // Default privileges davaju app_rw SELECT, INSERT; tieto tabulky su pre aplikaciu len na citanie.
        $this->db->query('REVOKE INSERT ON legal_profiles, legal_texts FROM app_rw');

        $at = '2026-10-25 00:00:00+00';

        $profiles = [
            ['SK', 'sk_non_vat', null, null],
            ['CZ', 'cz_non_vat', null, null],
            ['PL', 'pl_exempt_113_1', null, 'ART_113_1'],
            ['PL', 'pl_exempt_113_9', null, 'ART_113_9'],
            ['PL', 'pl_exempt_113_1', 'eu_b2b', 'ART_28B_RC'],
            ['PL', 'pl_exempt_113_9', 'eu_b2b', 'ART_28B_RC'],
        ];

        foreach ($profiles as [$country, $regime, $mode, $code]) {
            $this->db->query(
                'INSERT INTO legal_profiles (country, vat_regime, billing_mode, exemption_code, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$country, $regime, $mode, $code, $at],
            );
        }

        $texts = [
            ['SK', 'sk_non_vat', null, 'sk', 'Nie som platiteľ DPH.'],
            ['SK', 'sk_non_vat', null, 'en', 'Not a VAT payer.'],
            ['CZ', 'cz_non_vat', null, 'cs', 'Nejsem plátce DPH.'],
            ['CZ', 'cz_non_vat', null, 'en', 'Not a VAT payer.'],
        ];

        foreach (['1', '9'] as $n) {
            $regime  = 'pl_exempt_113_' . $n;
            $texts[] = ['PL', $regime, null, 'pl', 'Sprzedawca zwolniony podmiotowo z podatku VAT na podstawie art. 113 ust. ' . $n . ' ustawy o VAT.'];
            $texts[] = ['PL', $regime, null, 'en', 'Seller exempt from VAT under Art. 113(' . $n . ') of the Polish VAT Act.'];
            $texts[] = ['PL', $regime, null, 'sk', 'Predávajúci je oslobodený od DPH podľa čl. 113 ods. ' . $n . ' poľského zákona o DPH.'];
            $texts[] = ['PL', $regime, 'eu_b2b', 'pl', 'Odwrotne obciążenie.'];
            $texts[] = ['PL', $regime, 'eu_b2b', 'en', 'Reverse charge.'];
            $texts[] = ['PL', $regime, 'eu_b2b', 'sk', 'Prenesenie daňovej povinnosti.'];
        }

        foreach ($texts as [$country, $regime, $mode, $language, $body]) {
            $this->db->query(
                'INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES (?, ?, ?, ?, ?, ?, true, ?)',
                [$country, $regime, $mode, $language, 'vat_note', $body, $at],
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS legal_texts');
        $this->db->query('DROP TABLE IF EXISTS legal_profiles');
    }
}
