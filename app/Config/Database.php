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
        if (PHP_SAPI === 'cli') {
            $argv  = service('superglobals')->server('argv');
            $group = self::migrationOwnerGroup(ENVIRONMENT, is_array($argv) ? $argv : []);
            if ($group !== null) {
                $this->defaultGroup = $group;
            }
        }
    }

    /**
     * Skupiny app_owner, na ktore sa smie prepnut defaultGroup pri `spark migrate* -g`.
     * V testing len tests_owner: `CI_ENVIRONMENT=testing spark migrate -g owner` nesmie
     * prepnut default zo skupiny `tests` na zivu DB (WR-09).
     *
     * @return list<string>
     */
    public static function migrationOwnerGroups(string $environment): array
    {
        return $environment === 'testing' ? ['tests_owner'] : ['owner', 'tests_owner'];
    }

    /**
     * Owner skupina z argv pre `spark migrate* -g <skupina>`, inak null. Argv cita rovnako
     * ako CodeIgniter\CLI\CLI::parseCommandLine() a Console::run() (hodnota volby = nasledujuci
     * argument bez `-`), takze napr. `spark --no-header migrate -g owner` funguje ako v CI4.
     *
     * @param array<array-key, mixed> $argv
     */
    public static function migrationOwnerGroup(string $environment, array $argv): ?string
    {
        $args = [];

        foreach (array_slice(array_values($argv), 1) as $arg) {
            $args[] = is_scalar($arg) ? (string) $arg : '';
        }

        $segments    = [];
        $options     = [];
        $optionValue = false;

        foreach ($args as $i => $arg) {
            if (! str_starts_with($arg, '-')) {
                if ($optionValue) {
                    $optionValue = false;
                } else {
                    $segments[] = $arg;
                }

                continue;
            }

            $value = null;
            if (isset($args[$i + 1]) && ! str_starts_with($args[$i + 1], '-')) {
                $value       = $args[$i + 1];
                $optionValue = true;
            }
            $options[ltrim($arg, '-')] = $value;
        }

        // Ako Console::run(): prikaz = prva hodnota z array_merge(segmenty, volby).
        $params  = array_merge($segments, $options);
        $command = array_shift($params) ?? '';
        if (! str_starts_with($command, 'migrate')) {
            return null;
        }

        $group = $options['g'] ?? null;

        return in_array($group, self::migrationOwnerGroups($environment), true) ? $group : null;
    }
}
