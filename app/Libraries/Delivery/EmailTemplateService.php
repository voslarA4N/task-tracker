<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Sablony e-mailov (MAIL-04, D-14, D-16): citanie a uprava s revizom a auditom v jednej transakcii.
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

    /**
     * Uprava predmetu a textu (D-15, D-16). Meni sa iba subject, body, revision a updated_at.
     *
     * @return int nova revizia (pri nezmenenom obsahu povodna, bez zapisu)
     *
     * @throws EmailTemplateRejected
     */
    public function update(int $id, string $subject, string $body, int $expectedRevision): int
    {
        $subject = trim($subject);
        $body    = str_replace(["\r\n", "\r"], "\n", $body);

        if ($subject === '' || trim($body) === '') {
            throw new EmailTemplateRejected('empty');
        }

        if (mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) {
            throw new EmailTemplateRejected('too_long');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw new EmailTemplateRejected('invalid');
        }

        $unknown = TemplateRenderer::unknownPlaceholders($subject . "\n" . $body);

        if ($unknown !== []) {
            throw new EmailTemplateRejected('unknown_placeholder', $unknown[0]);
        }

        $now = service('clock')->now()->format('Y-m-d H:i:sP');

        return Transaction::run($this->db, function () use ($id, $subject, $body, $expectedRevision, $now): int {
            $row = $this->db->query('SELECT * FROM email_templates WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();

            if ($row === null) {
                throw new EmailTemplateRejected('not_found');
            }

            $revision = (int) $row['revision'];

            if ($revision !== $expectedRevision) {
                throw new EmailTemplateRejected('stale');
            }

            $old = ['subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'revision' => $revision];

            if ($old['subject'] === $subject && $old['body'] === $body) {
                return $revision;
            }

            $new = ['subject' => $subject, 'body' => $body, 'revision' => $revision + 1];

            $this->db->query(
                'UPDATE email_templates SET subject = :s:, body = :b:, revision = revision + 1, updated_at = :now: WHERE id = :id:',
                ['s' => $subject, 'b' => $body, 'now' => $now, 'id' => $id],
            );
            $this->audit->recordChange('email_template', $id, 'update', $old, $new);

            return $revision + 1;
        });
    }
}
