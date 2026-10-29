<?php

declare(strict_types=1);

namespace Tests\Integration\Mail;

use App\Libraries\Mail\AppMailer;
use App\Libraries\Mail\OutgoingMessage;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Realne doruchenie do Mailpitu: Message-ID, BCC bez uniku, prilohy z bufferu (D-12, D-27).
 *
 * @internal
 */
#[Group('integration')]
final class MailpitDeliveryTest extends CIUnitTestCase
{
    private const API = 'http://mailpit:8025/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        $sock = @fsockopen('mailpit', 1025, $errno, $errstr, 2);

        if ($sock === false) {
            $this->markTestSkipped('Mailpit nie je dostupny.');
        }

        fclose($sock);
    }

    /**
     * @return array<string, mixed>|list<mixed>|string
     */
    private function get(string $path, bool $json = true): array|string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw = file_get_contents(self::API . $path, false, $ctx);
        $this->assertIsString($raw, 'Mailpit REST nedostupne: ' . $path);

        return $json ? (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $raw;
    }

    public function testDeliveryToMailpit(): void
    {
        $config               = config('Email');
        $config->transport    = 'smtp';
        $config->protocol     = 'smtp';
        $config->SMTPHost     = 'mailpit';
        $config->SMTPPort     = 1025;
        $config->SMTPCrypto   = '';
        $config->SMTPUser     = '';
        $config->SMTPPass     = '';
        $config->fromEmail    = 'noreply@vostack.dev';
        $config->envelopeFrom = 'noreply@vostack.dev';

        $mailer = new AppMailer();
        $id     = $mailer->messageIdFor();
        $pdf    = "%PDF-1.4 mailpit-test\n" . random_bytes(64);

        $outcome = $mailer->sendMessage(new OutgoingMessage(
            kind: 'invoice',
            fromEmail: 'faktury@vostack.dev',
            fromName: 'Tomas Voslar',
            replyTo: null,
            to: ['klient@example.test'],
            cc: [],
            bcc: ['archiv-tajny@vostack.dev'],
            subject: 'Mailpit test ' . $id,
            textBody: 'text',
            htmlBody: '<p>html</p>',
            attachments: [['name' => 'Faktura_1.pdf', 'bytes' => $pdf, 'mime' => 'application/pdf']],
            messageId: $id,
            inReplyTo: null,
        ));

        $this->assertTrue($outcome->ok);
        $this->assertNotNull($outcome->smtpResponse);

        $found = $this->get('/search?query=' . rawurlencode('subject:"Mailpit test"'));
        $match = null;

        foreach ((array) ($found['messages'] ?? []) as $m) {
            $detail = $this->get('/message/' . $m['ID']);

            if (('<' . ($detail['MessageID'] ?? '') . '>') === $id || ($detail['MessageID'] ?? '') === trim($id, '<>')) {
                $match = $detail;
                break;
            }
        }

        $this->assertNotNull($match, 'Sprava s Message-ID sa nenasla v Mailpite');
        // Mailpit v poli Bcc ukazuje obalku (RCPT TO), nie hlavicku; hlavicky kontrolujeme nizsie.
        $this->assertSame('archiv-tajny@vostack.dev', $match['Bcc'][0]['Address']);

        // Mailpit vo /headers dopocitava Bcc z obalky; skutocna hlavicka sa overuje v surovom zdroji spravy.
        $raw = (string) $this->get('/message/' . $match['ID'] . '/raw', false);
        // Mailpit sam vlozi ako prvy riadok 'Bcc:' z obalky; zvysok zdroja ho obsahovat nesmie.
        $this->assertSame(1, preg_match_all('/^Bcc:/mi', $raw));
        $this->assertStringStartsWith('Bcc: archiv-tajny@vostack.dev', $raw);
        $this->assertSame(1, substr_count($raw, 'archiv-tajny@vostack.dev'));
        $this->assertStringContainsString('Return-Path: <noreply@vostack.dev>', $raw);

        $this->assertCount(1, $match['Attachments']);
        $part = $this->get('/message/' . $match['ID'] . '/part/' . $match['Attachments'][0]['PartID'], false);
        $this->assertSame(hash('sha256', $pdf), hash('sha256', (string) $part));
    }
}
