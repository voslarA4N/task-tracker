<?php

declare(strict_types=1);

namespace Tests\Integration\Mail;

use App\Libraries\Mail\AppEmail;
use App\Libraries\Mail\AppMailer;
use App\Libraries\Mail\OutgoingMessage;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\TestLogger;
use Config\Email as EmailConfig;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CapturingEmail;

/**
 * AppMailer::sendMessage: jedna cesta odosielania (D-03, D-12, D-02, D-27).
 *
 * @internal
 */
#[Group('integration')]
final class AppMailerMessageTest extends CIUnitTestCase
{
    private ?CapturingEmail $last = null;
    private bool $nextResult      = true;
    private int $factoryCalls     = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $config               = config('Email');
        $config->transport    = 'smtp';
        $config->protocol     = 'smtp';
        $config->fromEmail    = 'noreply@vostack.dev';
        $config->envelopeFrom = 'noreply@vostack.dev';
        $this->nextResult     = true;
        $this->factoryCalls   = 0;
    }

    protected function tearDown(): void
    {
        $config               = config('Email');
        $config->transport    = 'smtp';
        $config->envelopeFrom = '';
        parent::tearDown();
    }

    private function mailer(): AppMailer
    {
        return new AppMailer(function (EmailConfig $c): AppEmail {
            $this->factoryCalls++;
            $email         = new CapturingEmail($c);
            $email->result = $this->nextResult;
            $this->last    = $email;

            return $email;
        });
    }

    /**
     * @param list<array{name: string, bytes: string, mime: string}> $attachments
     * @param list<string>                                           $bcc
     */
    private function message(array $attachments = [], array $bcc = [], ?string $replyTo = null, ?string $inReplyTo = null, string $fromName = 'Tomas'): OutgoingMessage
    {
        return new OutgoingMessage(
            kind: 'invoice',
            fromEmail: 'faktury@vostack.dev',
            fromName: $fromName,
            replyTo: $replyTo,
            to: ['klient@example.test'],
            cc: [],
            bcc: $bcc,
            subject: 'Faktura 2026-001',
            textBody: 'Textove telo SECRETBODY',
            htmlBody: '<p>HTML telo SECRETBODY</p>',
            attachments: $attachments,
            messageId: '<abc-123@vostack.dev>',
            inReplyTo: $inReplyTo,
        );
    }

    private function last(): CapturingEmail
    {
        $this->assertNotNull($this->last);

        return $this->last;
    }

    public function testCustomMessageIdOnce(): void
    {
        $outcome = $this->mailer()->sendMessage($this->message());

        $this->assertTrue($outcome->ok);
        $this->assertSame('smtp', $outcome->transport);
        $this->assertSame(1, substr_count($this->last()->capturedHeaders, 'Message-ID: <abc-123@vostack.dev>'));
        $this->assertSame(1, substr_count($this->last()->capturedHeaders, 'Message-ID:'));
    }

    public function testBccNeverInHeaders(): void
    {
        $this->mailer()->sendMessage($this->message(bcc: ['archiv@vostack.dev']));

        $this->assertStringNotContainsString('archiv@vostack.dev', $this->last()->capturedHeaders);
        $this->assertStringNotContainsString('Bcc:', $this->last()->capturedHeaders);
    }

    public function testInReplyToAndReferences(): void
    {
        $this->mailer()->sendMessage($this->message(inReplyTo: '<prev@vostack.dev>'));
        $this->assertStringContainsString('In-Reply-To: <prev@vostack.dev>', $this->last()->capturedHeaders);
        $this->assertStringContainsString('References: <prev@vostack.dev>', $this->last()->capturedHeaders);

        $this->mailer()->sendMessage($this->message());
        $this->assertStringNotContainsString('In-Reply-To', $this->last()->capturedHeaders);
        $this->assertStringNotContainsString('References', $this->last()->capturedHeaders);
    }

    public function testAttachmentsFromBuffer(): void
    {
        $a = '%PDF-1.4 A';
        $b = '%PDF-1.4 B';
        $this->mailer()->sendMessage($this->message([
            ['name' => 'Faktura_1.pdf', 'bytes' => $a, 'mime' => 'application/pdf'],
            ['name' => 'Vykaz_1.pdf', 'bytes' => $b, 'mime' => 'application/pdf'],
        ]));

        $body = $this->last()->capturedBody;
        $this->assertStringContainsString(rtrim(chunk_split(base64_encode($a))), $body);
        $this->assertStringContainsString(rtrim(chunk_split(base64_encode($b))), $body);
        $this->assertStringContainsString('Faktura_1.pdf', $body);
        $this->assertStringContainsString('Vykaz_1.pdf', $body);
        $this->assertStringContainsString('multipart/mixed', $this->last()->capturedHeaders . $body);
        $this->assertStringContainsString('text/plain', $body);
        $this->assertStringContainsString('text/html', $body);
    }

    public function testFreshInstancePerMessage(): void
    {
        $mailer = $this->mailer();
        $mailer->sendMessage($this->message([['name' => 'A.pdf', 'bytes' => '%PDF-1.4 AAAA', 'mime' => 'application/pdf']]));
        $mailer->sendMessage($this->message([['name' => 'B.pdf', 'bytes' => '%PDF-1.4 BBBB', 'mime' => 'application/pdf']]));

        $this->assertSame(2, $this->factoryCalls);
        $this->assertStringNotContainsString(base64_encode('%PDF-1.4 AAAA'), $this->last()->capturedBody);
        $this->assertStringContainsString(base64_encode('%PDF-1.4 BBBB'), str_replace(["\r", "\n"], '', $this->last()->capturedBody));
    }

    public function testReplyToAndEncodedFromName(): void
    {
        $this->mailer()->sendMessage($this->message(replyTo: 'ucto@firma.sk', fromName: 'Tomáš Voslař'));
        $h = $this->last()->capturedHeaders;

        $this->assertMatchesRegularExpression('/Reply-To:.*ucto@firma\.sk/', $h);
        $this->assertStringContainsString('faktury@vostack.dev', $h);
        $this->assertStringNotContainsString('Tomáš', $h);
    }

    public function testLogTransportSendsNothingAndLogsMetadataOnly(): void
    {
        config('Email')->transport = 'log';
        $outcome                   = $this->mailer()->sendMessage($this->message([['name' => 'TAJNY.pdf', 'bytes' => 'x', 'mime' => 'application/pdf']]));

        $this->assertSame(0, $this->factoryCalls);
        $this->assertTrue($outcome->ok);
        $this->assertSame('log', $outcome->transport);
        $this->assertNull($outcome->smtpResponse);
        $this->assertTrue(TestLogger::didLog('info', 'mail.suppressed kind=invoice to=klient@example.test', false));
        $this->assertTrue(TestLogger::didLog('info', 'subject=Faktura 2026-001', false));
        $this->assertFalse(TestLogger::didLog('info', 'SECRETBODY', false));
        $this->assertFalse(TestLogger::didLog('info', 'TAJNY.pdf', false));
    }

    public function testFailureReturnsNotOkAndLogsWithoutBody(): void
    {
        $this->nextResult = false;
        $outcome          = $this->mailer()->sendMessage($this->message());

        $this->assertFalse($outcome->ok);
        $this->assertTrue(TestLogger::didLog('error', 'mail.failed kind=invoice', false));
        $this->assertFalse(TestLogger::didLog('error', 'SECRETBODY', false));
    }

    public function testNonSmtpProtocolThrows(): void
    {
        config('Email')->protocol = 'mail';
        $this->expectException(LogicException::class);

        try {
            $this->mailer()->sendMessage($this->message(bcc: ['archiv@vostack.dev']));
        } finally {
            config('Email')->protocol = 'smtp';
        }
    }

    public function testMessageIdForUsesEnvelopeDomain(): void
    {
        $id = $this->mailer()->messageIdFor();
        $this->assertMatchesRegularExpression('/^<[0-9a-f-]{36}@vostack\.dev>$/', $id);

        $config               = config('Email');
        $config->envelopeFrom = '';
        $config->fromEmail    = 'x@work-tracker.loc';
        $this->assertMatchesRegularExpression('/@work-tracker\.loc>$/', $this->mailer()->messageIdFor());
    }
}
