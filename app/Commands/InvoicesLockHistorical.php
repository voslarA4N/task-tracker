<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Invoicing\ExternalInvoiceLocker;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Zamkne historicky vyfakturovane importovane casti na externu fakturu (D-22, WR-07).
 * Predvolene suchy beh, zapis az s --commit a vsetkymi tromi --expect-*.
 *
 * @internal
 */
final class InvoicesLockHistorical extends BaseCommand
{
    protected $group       = 'Invoices';
    protected $name        = 'invoices:lock-historical';
    protected $description = 'Zamkne importované časti mesiaca na historickú externú faktúru (predvolene suchý beh).';
    protected $usage       = 'invoices:lock-historical --client <id> --month <RRRR-MM> [--ref <text>] [--include-app-parts] [--expect-parts <N> --expect-hours <H> --expect-amount <A> --commit]';
    protected $options     = [
        '--client'        => 'ID klienta (povinné)',
        '--month'         => 'Fakturačný mesiac RRRR-MM (povinné)',
        '--ref'           => 'Odkaz na pôvodnú faktúru (voliteľné, max 300 znakov)',
        '--expect-parts'  => 'Očakávaný počet častí (povinné s --commit)',
        '--expect-hours'  => 'Očakávané hodiny (povinné s --commit)',
        '--expect-amount' => 'Očakávaná suma (povinné s --commit)',
        '--include-app-parts' => 'Zahrnúť aj časti pridané k importovaným úlohám až v aplikácii (predvolene sa vynechajú)',
        '--commit'            => 'Skutočný zápis do databázy',
    ];

    public function run(array $params): int
    {
        $client = $this->opt($params, 'client');
        $month  = $this->opt($params, 'month');
        $ref    = $this->opt($params, 'ref');
        $parts  = $this->opt($params, 'expect-parts');
        $hours  = $this->opt($params, 'expect-hours');
        $amount = $this->opt($params, 'expect-amount');
        $commit = array_key_exists('commit', $params) || CLI::getOption('commit') !== null;
        $appAll = array_key_exists('include-app-parts', $params) || CLI::getOption('include-app-parts') !== null;

        if (
            $client === null || preg_match('/^\d{1,18}$/D', $client) !== 1
                             || $month === null || BillingMonth::parse($month) === null
                             || ($ref !== null && preg_match('/^[^\x00-\x1F\x7F]{1,300}$/Du', $ref) !== 1)
                             || ($parts !== null && preg_match('/^\d{1,4}$/D', $parts) !== 1)
                             || ($hours !== null && preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $hours) !== 1)
                             || ($amount !== null && preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $amount) !== 1)
        ) {
            CLI::error('Neplatný vstup. Použitie: ' . $this->usage);

            return EXIT_ERROR;
        }

        try {
            $locker = new ExternalInvoiceLocker();

            if (! $commit) {
                $plan = $locker->plan((int) $client, $month, $appAll);

                CLI::write('Počet častí: ' . $plan['count']);
                CLI::write('Hodiny: ' . $plan['hours']);
                CLI::write('Suma: ' . $plan['amount'] . ' ' . implode(',', $plan['currencies']));

                if ($plan['parts'] !== []) {
                    CLI::table(array_map(
                        static fn (array $p): array => [(string) $p['id'], $p['title'], $p['hours'], $p['rate'], $p['amount'], $p['currency']],
                        $plan['parts'],
                    ), ['ID', 'Úloha', 'Hodiny', 'Sadzba', 'Suma', 'Mena']);
                }

                if ($plan['excluded'] !== []) {
                    CLI::write(
                        'Vynechané časti pridané v aplikácii k importovaným úlohám (ID ' . implode(', ', $plan['excluded']) . '). Zahrnúť ich možno iba výslovne cez --include-app-parts.',
                        'yellow',
                    );
                }

                CLI::write('Suchý beh, nič sa nezapísalo. Spustite znova s --commit a --expect-parts, --expect-hours, --expect-amount.', 'yellow');

                return EXIT_SUCCESS;
            }

            if ($parts === null || $hours === null || $amount === null) {
                CLI::error('S --commit sú povinné --expect-parts, --expect-hours aj --expect-amount.');

                return EXIT_ERROR;
            }

            $id = $locker->lock((int) $client, $month, $ref, (int) $parts, $hours, $amount, $appAll);
        } catch (DomainException|InvalidArgumentException|RuntimeException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Vytvorená externá faktúra id ' . $id . ', časti zamknuté.', 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function opt(array $params, string $name): ?string
    {
        $v = $params[$name] ?? CLI::getOption($name);

        return is_string($v) ? $v : null;
    }
}
