<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\EmailTemplateRejected;
use App\Libraries\Delivery\EmailTemplateService;
use App\Libraries\Delivery\TemplateRenderer;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DeliveryFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class EmailTemplateServiceTest extends PgTestCase
{
    use DeliveryFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    /**
     * @var list<array<string, mixed>>
     */
    private array $original = [];

    private int $id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->original = $this->owner()->query('SELECT id, subject, body, revision, updated_at FROM email_templates')->getResultArray();
        $this->id       = (int) $this->owner()->query("SELECT id FROM email_templates WHERE key = 'invoice' AND language = 'sk'")->getRow()->id;
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $r) {
            $this->owner()->query(
                'UPDATE email_templates SET subject = :s:, body = :b:, revision = :r:, updated_at = :u: WHERE id = :id:',
                ['s' => $r['subject'], 'b' => $r['body'], 'r' => $r['revision'], 'u' => $r['updated_at'], 'id' => $r['id']],
            );
        }
        $this->owner()->query("DELETE FROM audit_log WHERE entity_type = 'email_template'");
        $this->resetMail();
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function service(): EmailTemplateService
    {
        return new EmailTemplateService();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return $this->owner()->query('SELECT * FROM email_templates WHERE id = :id:', ['id' => $this->id])->getRowArray();
    }

    public function testUpdateBumpsRevisionAndAudits(): void
    {
        $this->assertSame(1, (int) $this->row()['revision']);
        $this->assertSame(2, $this->service()->update($this->id, 'Faktúra č. {{number}}', "Dobrý deň\r\n{{iban}}", 1));
        $row = $this->row();
        $this->assertSame(2, (int) $row['revision']);
        $this->assertSame('Faktúra č. {{number}}', $row['subject']);
        $this->assertSame("Dobrý deň\n{{iban}}", $row['body']);
        $this->assertStringStartsWith('2026-10-27', (string) $row['updated_at']);

        $audit = $this->owner()->query("SELECT * FROM audit_log WHERE entity_type = 'email_template' AND entity_id = :id: AND action = 'update'", ['id' => $this->id])->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertStringContainsString('Faktúra č.', json_encode($audit[0], JSON_UNESCAPED_UNICODE));
    }

    public function testUnchangedReturnsSameRevisionWithoutAudit(): void
    {
        $r = $this->row();
        $this->assertSame(1, $this->service()->update($this->id, $r['subject'], $r['body'], 1));
        $this->assertSame(0, (int) $this->owner()->query("SELECT count(*) AS c FROM audit_log WHERE entity_type = 'email_template'")->getRow()->c);
    }

    public function testStaleRevisionRejected(): void
    {
        $this->service()->update($this->id, 'A {{number}}', 'B', 1);

        try {
            $this->service()->update($this->id, 'C {{number}}', 'D', 1);
            $this->fail('expected stale');
        } catch (EmailTemplateRejected $e) {
            $this->assertSame('stale', $e->reason);
        }
        $this->assertSame('A {{number}}', $this->row()['subject']);
    }

    #[DataProvider('provideRejected')]
    public function testRejected(string $subject, string $body, string $reason, ?string $detail): void
    {
        try {
            $this->service()->update($this->id, $subject, $body, 1);
            $this->fail('expected rejection');
        } catch (EmailTemplateRejected $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($detail, $e->detail);
        }
        $this->assertSame(1, (int) $this->row()['revision']);
    }

    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function provideRejected(): iterable
    {
        yield 'unknown' => ['S', '{{iban}} {{foo}}', 'unknown_placeholder', 'foo'];

        yield 'unknown subject' => ['{{bar}}', 'x', 'unknown_placeholder', 'bar'];

        yield 'empty subject' => ['   ', 'x', 'empty', null];

        yield 'empty body' => ['S', "  \n ", 'empty', null];

        yield 'long subject' => [str_repeat('a', 201), 'x', 'too_long', null];

        yield 'long body' => ['S', str_repeat('a', 4001), 'too_long', null];

        yield 'crlf subject' => ["a\r\nBcc: x@y.z", 'x', 'invalid', null];
    }

    public function testNotFound(): void
    {
        $this->expectException(EmailTemplateRejected::class);
        $this->service()->update(999999, 'S', 'B', 1);
    }

    public function testNewTemplateUsedByLaterDraftsButNotEarlierSends(): void
    {
        $this->resetMasterTables();
        $this->configureMail();
        $inv = $this->issuedInvoice();
        $this->mailer()->send($this->sendCommand($inv['id']));
        $lang = (string) $this->owner()->query('SELECT doc_language FROM invoices WHERE id = :i:', ['i' => $inv['id']])->getRow()->doc_language;
        $tid  = (int) $this->owner()->query("SELECT id FROM email_templates WHERE key = 'invoice' AND language = :l:", ['l' => $lang])->getRow()->id;
        $old  = (string) $this->owner()->query('SELECT subject FROM invoice_sends WHERE invoice_id = :i:', ['i' => $inv['id']])->getRow()->subject;

        $this->service()->update($tid, 'Novy predmet {{number}}', 'Telo {{iban}}', 1);

        $draft = $this->mailer()->draft($inv['id'], 'invoice');
        $this->assertStringStartsWith('Novy predmet ', $draft['subject']);
        $this->assertSame($old, (string) $this->owner()->query('SELECT subject FROM invoice_sends WHERE invoice_id = :i:', ['i' => $inv['id']])->getRow()->subject);
    }

    public function testSampleValuesCoverAllPlaceholders(): void
    {
        $v = TemplateRenderer::sampleValues('sk');
        $this->assertSame(TemplateRenderer::PLACEHOLDERS, array_keys($v));
        $this->assertStringContainsString('€', $v['total']);
        $this->assertSame('202610001', $v['number']);
    }
}
