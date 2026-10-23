<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * Aplikacia: app_rw (SELECT/INSERT, UPDATE/DELETE iba cez grantMutable). Hesla z .env.
     *
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'        => '',
        'hostname'   => 'db',
        'username'   => 'app_rw',
        'password'   => '',
        'database'   => 'task_tracker',
        'schema'     => 'public',
        'DBDriver'   => 'Postgre',
        'DBPrefix'   => '',
        'pConnect'   => false,
        'DBDebug'    => true,
        'charset'    => 'utf8',
        'swapPre'    => '',
        'failover'   => [],
        'port'       => 5432,
        'dateFormat' => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * Migracie a seedery: app_owner (spark migrate -g owner).
     *
     * @var array<string, mixed>
     */
    public array $owner = [
        'DSN'        => '',
        'hostname'   => 'db',
        'username'   => 'app_owner',
        'password'   => '',
        'database'   => 'task_tracker',
        'schema'     => 'public',
        'DBDriver'   => 'Postgre',
        'DBPrefix'   => '',
        'pConnect'   => false,
        'DBDebug'    => true,
        'charset'    => 'utf8',
        'swapPre'    => '',
        'failover'   => [],
        'port'       => 5432,
        'dateFormat' => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * PHPUnit ako aplikacia (app_rw); defaultGroup v prostredi testing.
     *
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'        => '',
        'hostname'   => 'db',
        'username'   => 'app_rw',
        'password'   => '',
        'database'   => 'task_tracker_test',
        'schema'     => 'public',
        'DBDriver'   => 'Postgre',
        'DBPrefix'   => '',
        'pConnect'   => false,
        'DBDebug'    => true,
        'charset'    => 'utf8',
        'swapPre'    => '',
        'failover'   => [],
        'port'       => 5432,
        'dateFormat' => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * Migracie testovacej DB (spark migrate -g tests_owner).
     *
     * @var array<string, mixed>
     */
    public array $tests_owner = [
        'DSN'        => '',
        'hostname'   => 'db',
        'username'   => 'app_owner',
        'password'   => '',
        'database'   => 'task_tracker_test',
        'schema'     => 'public',
        'DBDriver'   => 'Postgre',
        'DBPrefix'   => '',
        'pConnect'   => false,
        'DBDebug'    => true,
        'charset'    => 'utf8',
        'swapPre'    => '',
        'failover'   => [],
        'port'       => 5432,
        'dateFormat' => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // Ensure that we always set the database group to 'tests' if
        // we are currently running an automated test suite, so that
        // we don't overwrite live data on accident.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }

        // MigrationRunner vytvara tabulku `migrations` na default spojeni a `-g` iba filtruje
        // migracie. Aby aj ona patrila app_owner, pri `spark migrate* -g <owner skupina>` prepneme
        // defaultGroup na tuto skupinu (migracie bezia len ako app_owner).
        $argv = service('superglobals')->server('argv') ?? [];
        if (PHP_SAPI === 'cli' && str_starts_with((string) ($argv[1] ?? ''), 'migrate')) {
            $i = array_search('-g', $argv, true);
            $g = $i === false ? null : ($argv[$i + 1] ?? null);
            if (in_array($g, ['owner', 'tests_owner'], true)) {
                $this->defaultGroup = $g;
            }
        }
    }
}
