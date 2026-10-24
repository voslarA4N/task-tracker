<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\CLIRequest;
use Config\Database;

/**
 * Insert-only denník autentifikačných udalostí (AUTH-07). Heslo ani e-mail sa neukladajú.
 */
final class AuditLog
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

    public function record(AuthEvent $event, bool $success, ?int $userId, ?string $email = null, ?string $detail = null): void
    {
        $request = service('request');
        $cli     = $request instanceof CLIRequest;
        $ua      = $cli ? null : mb_substr($request->getUserAgent()->getAgentString(), 0, 512);

        $this->db->table('auth_events')->insert([
            'occurred_at'     => service('clock')->now()->format('Y-m-d H:i:sP'),
            'user_id'         => $userId,
            'event'           => $event->value,
            'success'         => $success ? 'true' : 'false',
            'ip'              => $cli ? 'cli' : $request->getIPAddress(),
            'user_agent'      => $ua === '' ? null : $ua,
            'device_label'    => $ua === null || $ua === '' ? null : DeviceLabel::fromUserAgent($ua),
            'identifier_hash' => $email === null ? null : hash('sha256', mb_strtolower(trim($email))),
            'detail'          => $detail === null ? null : mb_substr($detail, 0, 200),
        ]);
    }
}
