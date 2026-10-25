<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use ReflectionProperty;
use Throwable;

/**
 * Jedno miesto pre transakcie nad zdieľaným spojením (WR-01).
 *
 * Bezpečné pre vnorenie (napr. vystavenie faktúry v Phase 5 volá master služby vo vlastnej transakcii):
 * - režim transException volajúceho sa po návrate obnoví (nevypína ho natvrdo),
 * - stav transakcie sa resetuje iba na najvyššej úrovni; vo vnorenej úrovni sa chyba
 *   naopak prepíše do vonkajšej transakcie, aby jej transComplete() urobil rollback.
 */
final class Transaction
{
    /**
     * @template T
     *
     * @param BaseConnection<mixed, mixed> $db
     * @param callable(): T                $work
     *
     * @return T
     */
    public static function run(BaseConnection $db, callable $work): mixed
    {
        $outer             = $db->transDepth > 0;
        $previousException = self::exceptionMode($db);

        if (! $outer) {
            // Na najvyššej úrovni nie je čo chrániť: starý neúspech iného kódu nesmie potichu zrušiť túto transakciu.
            $db->resetTransStatus();
        }

        $db->transException(true)->transStart();
        $level = $db->transDepth;

        try {
            $result = $work();

            if (! $db->transComplete()) {
                throw new DatabaseException('Transaction was rolled back.');
            }

            return $result;
        } catch (Throwable $e) {
            // Rollback len vlastnej úrovne, ak je ešte otvorená (zlyhaný dotaz alebo transComplete ju už mohli ukončiť).
            if ($level > 0 && $db->transDepth === $level) {
                $db->transRollback();
            }

            if ($outer) {
                // Vnorená úroveň: CI4 len zníži hĺbku. Neúspech musí ostať viditeľný pre vonkajšiu transakciu.
                $db->handleTransStatus();
            } else {
                // Zlyhaný dotaz nechá transStatus = false, čo by potichu zrušilo ďalšiu transakciu na tom istom spojení.
                $db->resetTransStatus();
            }

            throw $e;
        } finally {
            $db->transException($previousException);
        }
    }

    /**
     * BaseConnection nemá getter pre transException (vlastnosť je protected).
     *
     * @param BaseConnection<mixed, mixed> $db
     */
    private static function exceptionMode(BaseConnection $db): bool
    {
        return (new ReflectionProperty(BaseConnection::class, 'transException'))->getValue($db) === true;
    }
}
