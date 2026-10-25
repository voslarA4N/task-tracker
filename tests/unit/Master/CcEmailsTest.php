<?php

declare(strict_types=1);

namespace Tests\Unit\Master;

use App\Libraries\Master\CcEmails;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class CcEmailsTest extends CIUnitTestCase
{
    public function testParseSplitsLowercasesAndDeduplicates(): void
    {
        $this->assertSame(['a@x.sk', 'b@x.sk'], CcEmails::parse("A@x.sk, b@x.sk;\nA@x.sk\n\n"));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], CcEmails::parse("  \n ; , "));
        $this->assertNull(CcEmails::validate([]));
    }

    public function testTooMany(): void
    {
        $emails = CcEmails::parse('a@x.sk,b@x.sk,c@x.sk,d@x.sk,e@x.sk,f@x.sk');
        $this->assertCount(6, $emails);
        $this->assertSame(lang('Clients.errorCcTooMany'), CcEmails::validate($emails));
    }

    public function testFiveAreAllowed(): void
    {
        $this->assertNull(CcEmails::validate(CcEmails::parse('a@x.sk,b@x.sk,c@x.sk,d@x.sk,e@x.sk')));
    }

    public function testInvalidAddressIsNamed(): void
    {
        $error = CcEmails::validate(CcEmails::parse('ok@x.sk, zle@'));
        $this->assertNotNull($error);
        $this->assertStringContainsString('zle@', $error);
    }

    public function testHeaderInjectionDoesNotSurvive(): void
    {
        $parsed = CcEmails::parse("a@x.sk\rBcc: y@z.sk");

        foreach ($parsed as $address) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $address);
        }

        $this->assertNotNull(CcEmails::validate($parsed));
        $this->assertNotNull(CcEmails::validate(["a@x.sk\r\nBcc: y@z.sk"]));
    }
}
