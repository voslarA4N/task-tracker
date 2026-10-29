<?php

declare(strict_types=1);

namespace Tests\Integration\Delivery;

use App\Libraries\Delivery\EmailTemplateService;
use App\Libraries\Delivery\TemplateRenderer;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class EmailTemplatesSeedTest extends PgTestCase
{
    public function testSeedHasEightTemplatesWithPlaceholders(): void
    {
        $rows = $this->owner()->query('SELECT key, language, subject, body FROM email_templates')->getResultArray();
        $this->assertCount(8, $rows);
        $pairs = [];

        foreach ($rows as $r) {
            $pairs[$r['key'] . '/' . $r['language']] = true;
            $this->assertStringContainsString('{{number}}', $r['subject']);
            $this->assertSame([], TemplateRenderer::unknownPlaceholders($r['subject'] . $r['body']));

            if ($r['key'] === 'reminder') {
                $this->assertStringContainsString('{{balance}}', $r['body']);
                $this->assertStringContainsString('{{days_overdue}}', $r['body']);
            }
        }
        $this->assertCount(8, $pairs);
    }

    public function testRwPrivileges(): void
    {
        $rw = $this->rw();
        $rw->transStart();
        $rw->query("UPDATE email_templates SET subject = 'x {{number}}' WHERE key = 'invoice' AND language = 'sk'");
        $rw->query("INSERT INTO email_templates (key, language, subject, body, updated_at) VALUES ('invoice', 'sk', 's', 'b', now()) ON CONFLICT DO NOTHING");
        $rw->transRollback();
        $this->addToAssertionCount(1);
    }

    public function testRwCannotChangeKeyOrDelete(): void
    {
        $rw = $this->rw();
        $this->assertPermissionDenied(static fn () => $rw->query("UPDATE email_templates SET key = 'reminder' WHERE id = (SELECT 1)"));
        $this->assertPermissionDenied(static fn () => $rw->query('DELETE FROM email_templates'));
    }

    public function testEffectiveAndFallback(): void
    {
        $owner   = $this->owner();
        $service = new EmailTemplateService($owner);

        $pl = $service->effective('invoice', 'pl');
        $this->assertSame('pl', $pl['language']);
        $this->assertFalse($pl['fallback']);

        $owner->transStart();
        $owner->query("DELETE FROM email_templates WHERE key = 'invoice' AND language = 'cs'");
        $cs = $service->effective('invoice', 'cs');
        $owner->transRollback();

        $this->assertSame('en', $cs['language']);
        $this->assertTrue($cs['fallback']);
    }
}
