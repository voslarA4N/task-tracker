<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Baza integracnych testov: owner() = app_owner (DDL), rw() = app_rw (ako aplikacia).
 * Zamerne bez DatabaseTestTrait ($migrate/$refresh by bezali pod app_rw a zakryli opravnenia).
 */
abstract class PgTestCase extends CIUnitTestCase
{
    private static ?BaseConnection $owner = null;
    private static ?BaseConnection $rw    = null;

    protected static function ownerConn(): BaseConnection
    {
        return self::$owner ??= Database::connect('tests_owner', false);
    }

    protected static function rwConn(): BaseConnection
    {
        return self::$rw ??= Database::connect('tests', false);
    }

    protected function owner(): BaseConnection
    {
        return self::ownerConn();
    }

    protected function rw(): BaseConnection
    {
        return self::rwConn();
    }

    protected function assertPermissionDenied(callable $fn): void
    {
        try {
            $fn();
        } catch (DatabaseException $e) {
            $this->assertMatchesRegularExpression('/permission denied|must be owner/i', $e->getMessage());

            return;
        }

        $this->fail('Expected DatabaseException (permission denied), none thrown.');
    }
}
