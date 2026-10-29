<?php

declare(strict_types=1);

namespace Tests\Integration\Mail;

use App\Libraries\Mail\AppEmail;
use App\Libraries\Mail\OutgoingMessage;
use App\Libraries\Mail\SendOutcome;
use App\Libraries\Platform\Uuid;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Email as EmailConfig;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\Support\CapturingEmail;

/**
 * AppEmail: vlastny Message-ID, SMTP obalka, zachytenie odpovede servera (D-12, D-27).
 *
 * @internal
 */
#[Group('integration')]
final class AppEmailTest extends CIUnitTestCase
{
    private function capturing(): CapturingEmail
    {
        $config           = new EmailConfig();
        $config->protocol = 'smtp';

        return new CapturingEmail($config);
    }

    /**
     * AppEmail s konektom na lokalny socket pair (bez siete), cez ktory vidime posielane riadky.
     *
     * @return array{0: object, 1: resource}
     */
    private function socketEmail(string $cannedReplies): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        fwrite($pair[1], $cannedReplies);

        $config = new EmailConfig();
        $email  = new class ($config) extends AppEmail {
            /**
             * @param resource $conn
             */
            public function useConnection($conn): void
            {
                $this->SMTPConnect = $conn;
            }

            public function cmd(string $cmd, string $data = ''): bool
            {
                return $this->sendCommand($cmd, $data);
            }

            public function data(string $data): bool
            {
                return $this->sendData($data);
            }

            public function reply(): string
            {
                return $this->getSMTPData();
            }
        };
        $email->useConnection($pair[0]);

        return [$email, $pair[1]];
    }

    public function testUuidV4FormatAndUniqueness(): void
    {
        $a = Uuid::v4();
        $b = Uuid::v4();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function testCustomMessageIdAppearsExactlyOnce(): void
    {
        $email = $this->capturing();
        $email->setMessageId('<abc-123@vostack.dev>')
            ->setFrom('faktury@vostack.dev', 'F')
            ->setTo('klient@example.test')
            ->setSubject('Test')
            ->setMessage('Telo');
        $email->send(false);

        $this->assertSame(1, substr_count($email->capturedHeaders, 'Message-ID: <abc-123@vostack.dev>'));
        $this->assertSame(1, substr_count($email->capturedHeaders, 'Message-ID:'));
    }

    public function testGeneratedMessageIdWhenNotSet(): void
    {
        $email = $this->capturing();
        $email->setFrom('faktury@vostack.dev', 'F')
            ->setTo('klient@example.test')
            ->setSubject('Test')
            ->setMessage('Telo');
        $email->send(false);

        $this->assertSame(1, substr_count($email->capturedHeaders, 'Message-ID:'));
    }

    public function testEnvelopeFromOverridesMailFrom(): void
    {
        [$email, $peer] = $this->socketEmail("250 ok\r\n250 ok\r\n");
        $email->setEnvelopeFrom('noreply@vostack.dev');
        $this->assertTrue($email->cmd('from', 'faktury@vostack.dev'));
        $this->assertStringContainsString('MAIL FROM:<noreply@vostack.dev>', (string) fread($peer, 4096));

        [$plain, $peer2] = $this->socketEmail("250 ok\r\n");
        $this->assertTrue($plain->cmd('from', 'faktury@vostack.dev'));
        $this->assertStringContainsString('MAIL FROM:<faktury@vostack.dev>', (string) fread($peer2, 4096));
    }

    public function testDataReplyCapturedAndQuitDoesNotOverwrite(): void
    {
        [$email, $peer] = $this->socketEmail("250 2.0.0 Message queued as X\r\n221 bye\r\n");

        $email->data("\r\n.");
        $email->reply();
        $this->assertSame("250 2.0.0 Message queued as X\r\n", $email->smtpReply());

        $email->cmd('quit');
        $this->assertIsResource($peer);
        $this->assertSame("250 2.0.0 Message queued as X\r\n", $email->smtpReply());
    }

    public function testDtosAreReadonly(): void
    {
        $outcome = new SendOutcome(true, 'log', null);
        $message = new OutgoingMessage('invoice', 'a@b.test', 'A', null, ['c@d.test'], [], [], 's', 't', 'h', [], '<x@y>', null);

        $this->assertTrue((new ReflectionProperty(SendOutcome::class, 'ok'))->isReadOnly());
        $this->assertTrue((new ReflectionProperty(OutgoingMessage::class, 'subject'))->isReadOnly());
        $this->assertTrue($outcome->ok);
        $this->assertSame('s', $message->subject);
    }
}
