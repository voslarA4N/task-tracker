<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\EmailTemplateService;
use App\Libraries\Delivery\TemplateContext;
use App\Libraries\Delivery\TemplateRenderer;
use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreditNoteFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\IssueFixtures;
use Tests\Support\MasterFixtures;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class CreditNoteTemplateTest extends PgTestCase
{
    use CreditNoteFixtures;
    use InvoiceFixtures;
    use IssueFixtures;
    use MasterFixtures;

    /**
     * @var list<array<string, mixed>>
     */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-31T10:00:00+01:00'));
        Services::injectMock('creditNoteIssuer', $this->creditNoteIssuer());
        $this->original = $this->owner()->query('SELECT id, subject, body, revision, updated_at FROM email_templates')->getResultArray();
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
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function document(int $id): array
    {
        $row             = $this->owner()->query('SELECT * FROM invoices WHERE id = :i:', ['i' => $id])->getRowArray();
        $row['snapshot'] = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }

    public function testEffectiveReturnsCreditNoteTemplate(): void
    {
        $t = (new EmailTemplateService())->effective('credit_note', 'sk');

        $this->assertStringContainsString('{{original_number}}', $t['subject']);
    }

    public function testUpdateBumpsRevision(): void
    {
        $id = (int) $this->owner()->query("SELECT id FROM email_templates WHERE key = 'credit_note' AND language = 'en'")->getRow()->id;

        $this->assertSame(2, (new EmailTemplateService())->update($id, 'Credit note {{number}} for {{original_number}}', 'Hello {{original_date}}', 1));
    }

    public function testListOrderedInvoiceReminderCreditNote(): void
    {
        $keys = array_values(array_unique(array_column((new EmailTemplateService())->listAll(), 'key')));

        $this->assertSame(['invoice', 'reminder', 'credit_note'], $keys);
    }

    public function testContextFillsOriginalValuesForCreditNote(): void
    {
        $s    = $this->issueSetup(['parts' => [['40.00', '2026-10']]]);
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));
        $cn   = $this->issueCreditNote($orig->id);

        $origRow = $this->document($orig->id);
        $cnRow   = $this->document($cn->id);
        $values  = TemplateContext::values($cnRow, '2026-10-31');

        $this->assertSame((string) $origRow['number'], $values['original_number']);
        $this->assertSame(TemplateContext::values($origRow, '2026-10-31')['issue_date'], $values['original_date']);
        $this->assertNotSame('', $values['original_date']);

        $t = (new EmailTemplateService())->effective('credit_note', (string) $cnRow['doc_language']);
        $r = (new TemplateRenderer())->render($t['subject'], $t['body'], $values);

        $this->assertStringContainsString((string) $cnRow['number'], $r['subject']);
        $this->assertStringContainsString((string) $origRow['number'], $r['subject']);
        $this->assertStringNotContainsString('{{', $r['text']);
    }

    public function testContextEmptyForInvoice(): void
    {
        $s    = $this->issueSetup(['parts' => [['40.00', '2026-10']]]);
        $orig = $this->issuer()->issue($this->issueCommand($s['client'], '2026-10', $s['entity'], [$s['parts'][0]]));

        $values = TemplateContext::values($this->document($orig->id), '2026-10-31');

        $this->assertSame('', $values['original_number']);
        $this->assertSame('', $values['original_date']);
    }
}
