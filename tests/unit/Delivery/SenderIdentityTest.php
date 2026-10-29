<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Libraries\Delivery\SenderIdentity;
use App\Libraries\Master\EntityInput;
use App\Libraries\Delivery\SenderRejected;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SenderIdentityTest extends CIUnitTestCase
{
    private string $original = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = config('Email')->allowedFromDomains;
    }

    protected function tearDown(): void
    {
        config('Email')->allowedFromDomains = $this->original;
        parent::tearDown();
    }

    private function reason(string $email): string
    {
        try {
            SenderIdentity::assertAllowed($email);
        } catch (SenderRejected $e) {
            return $e->reason;
        }

        return 'ok';
    }

    public function testAllowedDomainsAreTrimmedAndLowercased(): void
    {
        config('Email')->allowedFromDomains = 'vostack.dev, Example.org , ,vostack.dev';

        $this->assertSame(['vostack.dev', 'example.org'], SenderIdentity::allowedDomains());
    }

    public function testAssertAllowed(): void
    {
        config('Email')->allowedFromDomains = 'vostack.dev, Example.org ';

        $this->assertSame('ok', $this->reason('noreply@vostack.dev'));
        $this->assertSame('ok', $this->reason('X@EXAMPLE.org'));
        $this->assertSame('domain', $this->reason('x@evil.sk'));
        $this->assertSame('domain', $this->reason('x@sub.vostack.dev'));
        $this->assertSame('invalid', $this->reason('bez-zavinaca'));
        $this->assertSame('invalid', $this->reason("x@vostack.dev\r\nBcc: a@b.sk"));
    }

    public function testValidateSender(): void
    {
        config('Email')->allowedFromDomains = 'vostack.dev';
        $ok                                 = ['mail_from_name' => 'X', 'mail_from_email' => 'a@vostack.dev', 'mail_reply_to' => 'r@x.sk', 'mail_bcc' => ''];

        $this->assertSame([], EntityInput::validateSender($ok));
        $this->assertSame([], EntityInput::validateSender(['mail_from_name' => '', 'mail_from_email' => '', 'mail_reply_to' => '', 'mail_bcc' => '']));
        $this->assertArrayHasKey('mail_from_email', EntityInput::validateSender(['mail_from_email' => 'x@gmail.com'] + $ok));
        $this->assertArrayHasKey('mail_reply_to', EntityInput::validateSender(['mail_reply_to' => 'nie-mail'] + $ok));
        $this->assertArrayHasKey('mail_bcc', EntityInput::validateSender(['mail_bcc' => "a@x.sk\nb@x.sk\nc@x.sk\nd@x.sk\ne@x.sk\nf@x.sk"] + $ok));
        $this->assertArrayHasKey('mail_bcc', EntityInput::validateSender(['mail_bcc' => "a@x.sk\x01b@x.sk"] + $ok));
        $this->assertArrayHasKey('mail_from_name', EntityInput::validateSender(['mail_from_name' => str_repeat('a', 101)] + $ok));
        $this->assertStringContainsString('vostack.dev', EntityInput::validateSender(['mail_from_email' => 'x@gmail.com'] + $ok)['mail_from_email']);
    }
}
