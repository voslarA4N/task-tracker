<?php

declare(strict_types=1);

namespace Tests\Integration\StagingSeed;

use App\Commands\StagingSeed;
use App\Libraries\Auth\TotpService;
use App\Libraries\Master\SeriesFormat;
use App\Libraries\Platform\FrozenClock;
use App\Libraries\StagingSeed\HistoryPlanner;
use App\Libraries\StagingSeed\Profile;
use App\Libraries\StagingSeed\SeedAdminCredentials;
use App\Libraries\StagingSeed\SeedRefused;
use App\Libraries\StagingSeed\StagingSeeder;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Config\Staging;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * D-03: seed na testovacej DB ako app_rw (dokaz, ze staci opravnenia aplikacie), s malym profilom a dnom 2026-03-20.
 *
 * @internal
 */
#[Group('integration')]
final class StagingSeederTest extends PgTestCase
{
    use MasterFixtures;
    use StreamFilterTrait;

    private const TOKEN = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const TODAY = '2026-03-20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        Services::injectMock('clock', new FrozenClock(self::TODAY . 'T12:00:00+01:00'));
        config(Staging::class)->seed = true;
    }

    protected function tearDown(): void
    {
        config(Staging::class)->seed = false;
        Services::resetSingle('clock');
        $this->clean();
        parent::tearDown();
    }

    public function testSeedsConsistentHistoryThroughRealIssuePipeline(): void
    {
        $plan   = HistoryPlanner::build(Profile::small(), self::TODAY);
        $result = $this->seeder()->run(Profile::small());
        $db     = $this->rw();

        $this->assertSame(count($plan->entities), $result['counts']['entities']);
        $this->assertSame(count($plan->clients), $result['counts']['clients']);
        $this->assertSame(count($plan->tasks), $result['counts']['tasks']);
        $this->assertSame(count($plan->invoices), $result['counts']['invoices']);
        $this->assertGreaterThan(0, count($plan->invoices));

        // cislovanie bez medzier: v kazdom rade a obdobi po sebe iduce cisla od pociatocneho
        $series = $db->query("SELECT id, entity_id, format, initial_number FROM invoice_series WHERE kind = 'invoice'")->getResultArray();

        foreach ($series as $s) {
            $format  = SeriesFormat::parse((string) $s['format']);
            $rows    = $db->query('SELECT number, issue_date FROM invoices WHERE series_id = :s: ORDER BY id', ['s' => $s['id']])->getResultArray();
            $counter = [];

            foreach ($rows as $r) {
                $period = $format->periodKey((string) $r['issue_date']);
                $seq    = ($counter[$period] ?? (int) $s['initial_number'] - 1) + 1;

                $this->assertSame($format->render((string) $r['issue_date'], $seq), (string) $r['number']);
                $counter[$period] = $seq;
            }
        }

        // dokumenty a sucty
        $invoices = $db->query('SELECT id, total, issue_date, created_at, snapshot FROM invoices ORDER BY id')->getResultArray();

        foreach ($invoices as $inv) {
            $docs = $db->query('SELECT kind, byte_size FROM invoice_documents WHERE invoice_id = :i: ORDER BY kind', ['i' => $inv['id']])->getResultArray();

            $this->assertSame(['invoice', 'report'], array_column($docs, 'kind'));

            foreach ($docs as $d) {
                $this->assertGreaterThan(0, (int) $d['byte_size']);
            }

            $lines = (string) $db->query('SELECT COALESCE(SUM(amount), 0) AS s FROM invoice_lines WHERE invoice_id = :i:', ['i' => $inv['id']])->getRow()->s;
            $parts = (string) $db->query('SELECT COALESCE(SUM(amount), 0) AS s FROM billing_allocations WHERE invoice_id = :i:', ['i' => $inv['id']])->getRow()->s;
            $snap  = (string) ((array) ((array) json_decode((string) $inv['snapshot'], true))['totals'])['total'];

            $this->assertSame((string) $inv['total'], $lines);
            $this->assertSame((string) $inv['total'], $parts);
            $this->assertSame((string) $inv['total'], $snap);
            $this->assertLessThanOrEqual(self::TODAY, (string) $inv['issue_date']);
            $this->assertSame((string) $inv['issue_date'], substr((string) $inv['created_at'], 0, 10));
        }

        // rozsah datumov
        $span = $db->query('SELECT min(created_at)::date AS lo, max(created_at)::date AS hi FROM tasks')->getRowArray();
        $this->assertGreaterThanOrEqual('2026-01-01', (string) $span['lo']);
        $this->assertLessThanOrEqual('2026-01-07', (string) $span['lo']);
        $this->assertLessThanOrEqual(self::TODAY, (string) $span['hi']);

        // aktualny mesiac ostava nevyfakturovany
        $this->assertSame(0, (int) $db->query("SELECT count(*) AS c FROM invoices WHERE billing_month = '2026-03'")->getRow()->c);
        $this->assertGreaterThan(0, (int) $db->query("SELECT count(*) AS c FROM billing_allocations WHERE billing_month = '2026-03' AND invoice_id IS NULL")->getRow()->c);

        // admin s TOTP, ktoreho tajomstvo sa da desifrovat
        $user = $db->query("SELECT u.id, g.\"group\" AS grp, s.totp_enabled_at, s.totp_secret_enc FROM users u JOIN auth_groups_users g ON g.user_id = u.id JOIN user_security s ON s.user_id = u.id WHERE u.id = (SELECT user_id FROM auth_identities WHERE secret = 'seed.admin@example.test')")->getRowArray();

        $this->assertNotNull($user);
        $this->assertSame('admin', $user['grp']);
        $this->assertNotNull($user['totp_enabled_at']);
        $this->assertSame(self::TOKEN, (new TotpService())->decryptSecret((string) $user['totp_secret_enc']));

        // hodiny sa po behu vratili
        $this->assertSame(self::TODAY, service('clock')->today());
    }

    public function testFullProfileIssuesEveryPlannedInvoiceOnDeployDay(): void
    {
        // profil, ktory sa nasadzuje: ziadne odmietnutie vystavenia (QR, limity ZBP, verzia SK subjektu z 2026-07-01)
        Services::injectMock('clock', new FrozenClock('2026-10-28T12:00:00+01:00'));

        $plan   = HistoryPlanner::build(Profile::full(), '2026-10-28');
        $result = $this->seeder()->run(Profile::full());

        $this->assertSame(count($plan->tasks), $result['counts']['tasks']);
        $this->assertSame(count($plan->invoices), $result['counts']['invoices']);
        $this->assertSame(2 * count($plan->invoices), $result['counts']['invoice_documents']);
        $this->assertSame(
            2,
            (int) $this->rw()->query('SELECT count(*) AS c FROM entity_versions WHERE entity_id = (SELECT id FROM entities WHERE country = \'SK\')')->getRow()->c,
        );
    }

    public function testSecondRunOnNonEmptyDatabaseIsRefused(): void
    {
        $this->seeder()->run(Profile::small());

        try {
            $this->seeder()->run(Profile::small());
            $this->fail('Expected SeedRefused');
        } catch (SeedRefused $e) {
            $this->assertSame(SeedRefused::NOT_PRISTINE, $e->reason);
        }
    }

    public function testCommandWithoutFlagFailsAndWritesNothing(): void
    {
        config(Staging::class)->seed = false;

        $this->setUpStreamFilterTrait();

        try {
            $code = (new StagingSeed(service('logger'), service('commands')))->run([]);
            $out  = $this->getStreamFilterBuffer();
        } finally {
            $this->tearDownStreamFilterTrait();
        }

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('flag_off', $out);
        $this->assertSame(0, (int) $this->rw()->query('SELECT count(*) AS c FROM entities')->getRow()->c);
        $this->assertSame(0, (int) $this->rw()->query('SELECT count(*) AS c FROM users')->getRow()->c);
    }

    private function seeder(): StagingSeeder
    {
        return new StagingSeeder($this->rw(), SeedAdminCredentials::load('/nonexistent/seed_admin.env', 'testing', [
            'SEED_ADMIN_EMAIL'       => 'seed.admin@example.test',
            'SEED_ADMIN_PASSWORD'    => 'Zelena-Luka-2026!',
            'SEED_ADMIN_TOTP_SECRET' => self::TOKEN,
        ]));
    }

    private function clean(): void
    {
        $this->resetMasterTables();
        $this->owner()->query('TRUNCATE users, auth_identities, auth_logins, auth_groups_users, auth_permissions_users, auth_remember_tokens, user_security, auth_lockouts, auth_events RESTART IDENTITY CASCADE');
    }
}
