<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteCommand;
use App\Libraries\Invoicing\CreditNoteRejected;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Group('unit')]
final class CreditNoteCommandTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const UUID  = '123e4567-e89b-42d3-a456-426614174000';

    public function testCorrectionTypeValuesAndKeys(): void
    {
        $this->assertSame(CorrectionType::FxError, CorrectionType::from('fx_error'));
        $this->assertNull(CorrectionType::tryFrom('typo'));
        $this->assertCount(5, CorrectionType::cases());

        foreach (CorrectionType::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z_]{3,30}$/', $case->value);
        }

        $this->assertSame('cnTypeDataError', CorrectionType::DataError->pdfLabelKey());
        $this->assertSame('cnTypeAmountError', CorrectionType::AmountError->pdfLabelKey());
        $this->assertSame('cnTypeFxError', CorrectionType::FxError->pdfLabelKey());
        $this->assertSame('cnTypeOrderCancelled', CorrectionType::OrderCancelled->pdfLabelKey());
        $this->assertSame('cnTypeOther', CorrectionType::Other->pdfLabelKey());
        $this->assertSame('CreditNotes.typeDataError', CorrectionType::DataError->uiLabelKey());
        $this->assertSame('CreditNotes.typeOrderCancelled', CorrectionType::OrderCancelled->uiLabelKey());
        $this->assertSame('CreditNotes.typeOther', CorrectionType::Other->uiLabelKey());
    }

    public function testValidCommandTrimsReason(): void
    {
        $c = $this->make(['reason' => '  Chybná sadzba  ']);

        $this->assertSame('Chybná sadzba', $c->reason);
        $this->assertSame(5, $c->originalId);
        $this->assertFalse($c->confirmBackdate);
    }

    public function testReasonBoundaryAndNewline(): void
    {
        $this->assertSame(500, mb_strlen($this->make(['reason' => str_repeat('ž', 500)])->reason));
        $this->assertSame("a\nb", $this->make(['reason' => "a\nb"])->reason);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidProvider')]
    public function testRejectsInvalid(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->make($override);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'id zero' => [['originalId' => 0]];

        yield 'date feb 30' => [['issueDate' => '2026-02-30']];

        yield 'date dotted' => [['issueDate' => '31.10.2026']];

        yield 'reason empty' => [['reason' => '']];

        yield 'reason blanks' => [['reason' => '   ']];

        yield 'reason long' => [['reason' => str_repeat('a', 501)]];

        yield 'reason control' => [['reason' => "ab\x07c"]];

        yield 'token short' => [['previewToken' => str_repeat('a', 63)]];

        yield 'token upper' => [['previewToken' => str_repeat('A', 64)]];

        yield 'key bad' => [['requestKey' => 'abc']];
    }

    public function testRejectedCarriesContext(): void
    {
        $e = new CreditNoteRejected('already_credited', [], 9, 'DB-202610001');

        $this->assertSame('already_credited', $e->reason);
        $this->assertSame(9, $e->creditNoteId);
        $this->assertSame('DB-202610001', $e->creditNoteNumber);

        $this->expectException(InvalidArgumentException::class);

        new CreditNoteRejected('nope');
    }

    /**
     * @param array<string, mixed> $o
     */
    private function make(array $o = []): CreditNoteCommand
    {
        $a = $o + [
            'originalId'     => 5,
            'issueDate'      => '2026-10-31',
            'reason'         => 'Chybná sadzba',
            'correctionType' => CorrectionType::AmountError,
            'previewToken'   => self::TOKEN,
            'requestKey'     => self::UUID,
        ];

        return new CreditNoteCommand(...$a);
    }
}
