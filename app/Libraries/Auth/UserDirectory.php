<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Zoznam používateľov len na čítanie (UI-03, D-16). Iba SELECT, žiadne tajomstvá.
 */
final class UserDirectory
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{id: int, email: ?string, username: ?string, groups: list<string>, totp_enabled: bool, last_login: ?string}>
     */
    public function list(): array
    {
        $rows = $this->db->query(<<<'SQL'
            SELECT u.id, u.username, ai.secret AS email,
                   (us.totp_enabled_at IS NOT NULL) AS totp_enabled,
                   (SELECT MAX(e.occurred_at) FROM auth_events e
                     WHERE e.user_id = u.id AND e.success
                       AND e.event IN ('login_password_ok', 'login_totp_ok', 'login_no_totp', 'device_login')) AS last_login,
                   (SELECT string_agg(g."group", ',' ORDER BY g."group") FROM auth_groups_users g WHERE g.user_id = u.id) AS groups
              FROM users u
              LEFT JOIN auth_identities ai ON ai.user_id = u.id AND ai.type = 'email_password'
              LEFT JOIN user_security us ON us.user_id = u.id
             WHERE u.deleted_at IS NULL
             ORDER BY ai.secret NULLS LAST, u.id
            SQL)->getResultArray();

        $out = [];

        foreach ($rows as $r) {
            $totp  = $r['totp_enabled'];
            $out[] = [
                'id'           => (int) $r['id'],
                'email'        => $r['email'] !== null ? (string) $r['email'] : null,
                'username'     => $r['username'] !== null ? (string) $r['username'] : null,
                'groups'       => $r['groups'] === null || $r['groups'] === '' ? [] : explode(',', (string) $r['groups']),
                'totp_enabled' => $totp === true        || $totp === 't' || $totp === 1 || $totp === '1',
                'last_login'   => $r['last_login'] !== null ? (string) $r['last_login'] : null,
            ];
        }

        return $out;
    }
}
