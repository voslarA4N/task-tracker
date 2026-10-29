<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Audit\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Sablony e-mailov (MAIL-04, D-14). V tomto kroku iba citanie; uprava a audit pride neskor.
 */
class EmailTemplateService
{
    public const KEYS           = ['invoice', 'reminder'];
    public const LANGUAGE_ORDER = ['sk', 'pl', 'cs', 'en'];
    public const MAX_SUBJECT    = 200;
    public const MAX_BODY       = 4000;

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
     * Audit logger pre upravu sablon (pridava sa neskor).
     */
    protected function audit(): AuditLogger
    {
        return $this->audit;
    }

    /**
     * Sablona pre jazyk; ak chyba, anglicka (fallback true). Nikdy prazdny e-mail.
     *
     * @return array{id: int, key: string, language: string, subject: string, body: string, revision: int, fallback: bool}
     */
    public function effective(string $key, string $language): array
    {
        foreach ([[$language, false], ['en', true]] as [$lang, $fallback]) {
            $row = $this->db->query(
                'SELECT id, key, language, subject, body, revision FROM email_templates WHERE key = :key: AND language = :language:',
                ['key' => $key, 'language' => $lang],
            )->getRowArray();

            if ($row !== null) {
                return [
                    'id'       => (int) $row['id'],
                    'key'      => (string) $row['key'],
                    'language' => (string) $row['language'],
                    'subject'  => (string) $row['subject'],
                    'body'     => (string) $row['body'],
                    'revision' => (int) $row['revision'],
                    'fallback' => $fallback && $language !== 'en',
                ];
            }
        }

        throw new RuntimeException('E-mail template missing: ' . $key . '/' . $language);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->query('SELECT * FROM email_templates WHERE id = :id:', ['id' => $id])->getRowArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $rows = $this->db->query('SELECT * FROM email_templates')->getResultArray();

        usort($rows, static function (array $a, array $b): int {
            $ka = array_search($a['key'], self::KEYS, true);
            $kb = array_search($b['key'], self::KEYS, true);

            return [$ka, array_search($a['language'], self::LANGUAGE_ORDER, true)]
                <=> [$kb, array_search($b['language'], self::LANGUAGE_ORDER, true)];
        });

        return $rows;
    }
}
