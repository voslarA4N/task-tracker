<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;

/**
 * MAIL-04, D-14, D-16: editovatelne sablony e-mailov (faktura, upomienka) v 4 jazykoch. Seed 8 sablon.
 * app_rw: SELECT, INSERT a UPDATE len stlpcov subject, body, revision, updated_at. DELETE ani zmena key/language nie.
 */
final class CreateEmailTemplates extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE email_templates (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                key text NOT NULL CHECK (key IN ('invoice','reminder')),
                language text NOT NULL CHECK (language IN ('sk','pl','cs','en')),
                subject text NOT NULL CHECK (char_length(subject) BETWEEN 1 AND 200),
                body text NOT NULL CHECK (char_length(body) BETWEEN 1 AND 4000),
                revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1),
                updated_at timestamptz NOT NULL,
                CONSTRAINT email_templates_key_language UNIQUE (key, language)
            )
            SQL);
        $this->db->query("COMMENT ON TABLE email_templates IS 'Editable e-mail templates (MAIL-04, D-14); app_rw INSERT + column UPDATE, no DELETE'");

        $this->db->query('GRANT SELECT, INSERT ON email_templates TO app_rw');
        $this->grantUpdateColumns('email_templates', ['subject', 'body', 'revision', 'updated_at']);

        foreach ($this->seed() as [$key, $language, $subject, $body]) {
            $this->db->query(
                'INSERT INTO email_templates (key, language, subject, body, revision, updated_at) VALUES (:key:, :language:, :subject:, :body:, 1, now())',
                ['key' => $key, 'language' => $language, 'subject' => $subject, 'body' => $body],
            );
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE email_templates');
    }

    /**
     * @return list<array{string, string, string, string}>
     */
    private function seed(): array
    {
        return [
            ['invoice', 'sk', 'Faktúra {{number}}', "Dobrý deň,\n\nv prílohe posielame faktúru {{number}} za {{billing_month}} na sumu {{total}} a k nej výkaz práce.\nSplatnosť: {{due_date}}. Variabilný symbol: {{variable_symbol}}. IBAN: {{iban}}.\n\nĎakujeme.\n{{entity_name}}"],
            ['reminder', 'sk', 'Upomienka: faktúra {{number}}', "Dobrý deň,\n\ndovoľujeme si pripomenúť faktúru {{number}} so splatnosťou {{due_date}}. K dnešnému dňu evidujeme neuhradený zostatok {{balance}} ({{days_overdue}} dní po splatnosti).\nFaktúra je v prílohe. Ak ste už platili, správu prosím považujte za bezpredmetnú.\n\nĎakujeme.\n{{entity_name}}"],
            ['invoice', 'pl', 'Faktura {{number}}', "Dzień dobry,\n\nw załączeniu przesyłamy fakturę {{number}} za {{billing_month}} na kwotę {{total}} oraz wykaz wykonanej pracy.\nTermin płatności: {{due_date}}. Symbol zmienny: {{variable_symbol}}. IBAN: {{iban}}.\n\nDziękujemy.\n{{entity_name}}"],
            ['reminder', 'pl', 'Przypomnienie: faktura {{number}}', "Dzień dobry,\n\nuprzejmie przypominamy o fakturze {{number}} z terminem płatności {{due_date}}. Na dziś odnotowujemy nieopłaconą kwotę {{balance}} ({{days_overdue}} dni po terminie).\nFaktura jest w załączeniu. Jeśli płatność została już dokonana, prosimy uznać tę wiadomość za bezprzedmiotową.\n\nDziękujemy.\n{{entity_name}}"],
            ['invoice', 'cs', 'Faktura {{number}}', "Dobrý den,\n\nv příloze zasíláme fakturu {{number}} za {{billing_month}} na částku {{total}} a k ní výkaz práce.\nSplatnost: {{due_date}}. Variabilní symbol: {{variable_symbol}}. IBAN: {{iban}}.\n\nDěkujeme.\n{{entity_name}}"],
            ['reminder', 'cs', 'Upomínka: faktura {{number}}', "Dobrý den,\n\ndovolujeme si připomenout fakturu {{number}} se splatností {{due_date}}. K dnešnímu dni evidujeme neuhrazený zůstatek {{balance}} ({{days_overdue}} dní po splatnosti).\nFaktura je v příloze. Pokud jste již platili, považujte prosím tuto zprávu za bezpředmětnou.\n\nDěkujeme.\n{{entity_name}}"],
            ['invoice', 'en', 'Invoice {{number}}', "Hello,\n\nplease find attached invoice {{number}} for {{billing_month}} in the amount of {{total}}, together with the work report.\nDue date: {{due_date}}. Variable symbol: {{variable_symbol}}. IBAN: {{iban}}.\n\nThank you.\n{{entity_name}}"],
            ['reminder', 'en', 'Reminder: invoice {{number}}', "Hello,\n\nthis is a friendly reminder about invoice {{number}}, due on {{due_date}}. As of today the outstanding balance is {{balance}} ({{days_overdue}} days overdue).\nThe invoice is attached. If you have already paid, please disregard this message.\n\nThank you.\n{{entity_name}}"],
        ];
    }
}
