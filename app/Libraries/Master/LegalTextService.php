<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Platform\DbBool;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;

/**
 * Uprava zakonnych textov vlastnikom (SET-01, D-13, D-14). Kazda zmena zvysi revision a zapise audit
 * v tej istej transakcii. Vystavene doklady maju vlastnu kopiu znenia (snapshot), preto ich zmena neovplyvni.
 */
class LegalTextService
{
    public const MAX_BODY         = 2000;
    public const MAX_INVOICE_NOTE = 500;
    private const KEYS            = ['vat_note', 'invoice_note'];
    private const KEY_ORDER       = ['vat_note' => 0, 'invoice_note' => 1];
    private const LANGUAGE_ORDER  = ['sk' => 0, 'cs' => 1, 'pl' => 2, 'en' => 3];
    private const MODE_ORDER      = ['' => 0, 'eu_b2b' => 1, 'non_eu' => 2, 'domestic' => 3];

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AuditLogger $audit;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = $audit ?? new AuditLogger($this->db);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM legal_texts WHERE id = :id:', ['id' => $id])->getRowArray();

        return $row ?? null;
    }

    /**
     * @return list<array{country: string, vat_regime: string, profiles: list<array{billing_mode: ?string, exemption_code: ?string, vat_code: ?string}>, texts: list<array<string, mixed>>}>
     */
    public function listGrouped(): array
    {
        $groups = [];

        $profiles = $this->db->query(
            'SELECT country, vat_regime, billing_mode, exemption_code, vat_code FROM legal_profiles ORDER BY country DESC, vat_regime, billing_mode NULLS FIRST',
        )->getResultArray();

        foreach ($profiles as $p) {
            $k = $p['country'] . '|' . $p['vat_regime'];
            $groups[$k] ??= ['country' => (string) $p['country'], 'vat_regime' => (string) $p['vat_regime'], 'profiles' => [], 'texts' => []];

            $groups[$k]['profiles'][] = [
                'billing_mode'   => $p['billing_mode'] === null ? null : (string) $p['billing_mode'],
                'exemption_code' => $p['exemption_code'] === null ? null : (string) $p['exemption_code'],
                'vat_code'       => $p['vat_code'] === null ? null : (string) $p['vat_code'],
            ];
        }

        $texts = $this->db->query('SELECT * FROM legal_texts')->getResultArray();

        foreach ($texts as $t) {
            $k = $t['country'] . '|' . $t['vat_regime'];
            $groups[$k] ??= ['country' => (string) $t['country'], 'vat_regime' => (string) $t['vat_regime'], 'profiles' => [], 'texts' => []];

            $t['provisional']      = DbBool::of($t['provisional']);
            $groups[$k]['texts'][] = $t;
        }

        foreach ($groups as &$group) {
            usort($group['texts'], static fn (array $a, array $b): int => [
                self::KEY_ORDER[$a['key']] ?? 9, self::LANGUAGE_ORDER[$a['language']] ?? 9, self::MODE_ORDER[(string) $a['billing_mode']] ?? 9, (int) $a['id'],
            ] <=> [
                self::KEY_ORDER[$b['key']] ?? 9, self::LANGUAGE_ORDER[$b['language']] ?? 9, self::MODE_ORDER[(string) $b['billing_mode']] ?? 9, (int) $b['id'],
            ]);
        }
        unset($group);

        uasort($groups, static fn (array $a, array $b): int => [$b['country'], $a['vat_regime']] <=> [$a['country'], $b['vat_regime']]);

        return array_values($groups);
    }

    /**
     * @return int nova revizia (pri nezmenenom znení povodna, bez zapisu)
     *
     * @throws LegalTextRejected
     */
    public function update(int $id, string $body, bool $provisional, int $expectedRevision): int
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (trim($body) === '') {
            throw new LegalTextRejected('empty');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw new LegalTextRejected('too_long');
        }

        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($id, $body, $provisional, $expectedRevision, $now): int {
            $row = $this->db->query('SELECT * FROM legal_texts WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();

            if ($row === null) {
                throw new LegalTextRejected('not_found');
            }

            if ($row['key'] === 'invoice_note' && mb_strlen($body) > self::MAX_INVOICE_NOTE) {
                throw new LegalTextRejected('too_long');
            }

            $revision = (int) $row['revision'];

            if ($revision !== $expectedRevision) {
                throw new LegalTextRejected('stale');
            }

            $old = ['body' => (string) $row['body'], 'provisional' => DbBool::of($row['provisional']), 'revision' => $revision];

            if ($old['body'] === $body && $old['provisional'] === $provisional) {
                return $revision;
            }

            $new = ['body' => $body, 'provisional' => $provisional, 'revision' => $revision + 1];

            $this->db->query(
                'UPDATE legal_texts SET body = :b:, provisional = CAST(:p: AS boolean), revision = revision + 1, updated_at = :now: WHERE id = :id:',
                ['b' => $body, 'p' => DbBool::toDb($provisional), 'now' => $now, 'id' => $id],
            );
            $this->audit->recordChange('legal_text', $id, 'update', $old, $new);

            return $revision + 1;
        });
    }

    /**
     * Pridanie variantu znenia (D-13). Whitelist kluca, jazyka, rezimu a existujuceho profilu (D-12, T-06-33).
     *
     * @return int id noveho riadku
     *
     * @throws LegalTextRejected
     */
    public function create(string $country, string $vatRegime, ?string $billingMode, string $language, string $key, string $body, bool $provisional): int
    {
        if (! in_array($key, self::KEYS, true)
            || DocLanguage::tryFrom($language) === null
            || ($billingMode !== null && BillingMode::tryFrom($billingMode) === null)) {
            throw new LegalTextRejected('invalid');
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (trim($body) === '') {
            throw new LegalTextRejected('empty');
        }

        if (mb_strlen($body) > self::MAX_BODY || ($key === 'invoice_note' && mb_strlen($body) > self::MAX_INVOICE_NOTE)) {
            throw new LegalTextRejected('too_long');
        }

        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        try {
            return Transaction::run($this->db, function () use ($country, $vatRegime, $billingMode, $language, $key, $body, $provisional, $now): int {
                $profile = $this->db->query(
                    'SELECT 1 FROM legal_profiles WHERE country = :c: AND vat_regime = :r: LIMIT 1',
                    ['c' => $country, 'r' => $vatRegime],
                )->getRowArray();

                if ($profile === null) {
                    throw new LegalTextRejected('invalid');
                }

                $exists = $this->db->query(
                    'SELECT 1 FROM legal_texts WHERE country = :c: AND vat_regime = :r: AND language = :l: AND key = :k: '
                    . 'AND billing_mode IS NOT DISTINCT FROM CAST(:m: AS text)',
                    ['c' => $country, 'r' => $vatRegime, 'l' => $language, 'k' => $key, 'm' => $billingMode],
                )->getRowArray();

                if ($exists !== null) {
                    throw new LegalTextRejected('exists');
                }

                $this->db->table('legal_texts')->insert([
                    'country'      => $country,
                    'vat_regime'   => $vatRegime,
                    'billing_mode' => $billingMode,
                    'language'     => $language,
                    'key'          => $key,
                    'body'         => $body,
                    'provisional'  => DbBool::toDb($provisional),
                    'revision'     => 1,
                    'updated_at'   => $now,
                ]);
                $id = (int) $this->db->insertID();

                $this->audit->record('legal_text', $id, 'create', null, [
                    'country'  => $country, 'vat_regime' => $vatRegime, 'billing_mode' => $billingMode,
                    'language' => $language, 'key' => $key, 'body' => $body, 'provisional' => $provisional, 'revision' => 1,
                ]);

                return $id;
            });
        } catch (DatabaseException $e) {
            // Subezny INSERT rovnakej kombinacie (unique legal_texts_key); zachytene az mimo transakcie.
            if (InvoiceIssuer::isUniqueViolation($e, 'legal_texts_key')) {
                throw new LegalTextRejected('exists');
            }

            throw $e;
        }
    }

    /**
     * Znenie, ktore sa pre kombinaciu realne pouzije (rovnake poradie ako pri vystaveni), s priznakom nahradneho.
     *
     * @return array{id: int, body: string, language: string, billing_mode: ?string, revision: int, provisional: bool, fallback: bool}|null
     */
    public function effective(string $country, string $vatRegime, ?string $billingMode, string $language, string $key): ?array
    {
        $resolver = new LegalProfileResolver($this->db);
        $row      = $resolver->optionalText($country, $vatRegime, $billingMode ?? '', $language, $key);

        if ($row === null) {
            return null;
        }

        $id = $this->db->query(
            'SELECT id FROM legal_texts WHERE country = :c: AND vat_regime = :r: AND language = :l: AND key = :k: '
            . 'AND billing_mode IS NOT DISTINCT FROM CAST(:m: AS text)',
            ['c' => $country, 'r' => $vatRegime, 'l' => $row['language'], 'k' => $key, 'm' => $row['billing_mode']],
        )->getRowArray();

        return [
            'id'           => (int) ($id['id'] ?? 0),
            'body'         => $row['body'],
            'language'     => $row['language'],
            'billing_mode' => $row['billing_mode'],
            'revision'     => $row['revision'],
            'provisional'  => $row['provisional'],
            'fallback'     => $row['language'] !== $language || $row['billing_mode'] !== $billingMode,
        ];
    }
}
