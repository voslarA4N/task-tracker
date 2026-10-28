<?php

declare(strict_types=1);

namespace Tests\Unit\Invoicing;

use App\Libraries\Invoicing\PdfFormat;
use App\Libraries\Invoicing\ReportRows;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class ReportRowFormatTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function line(string $hours = '16.00', ?string $est = '40.00', ?string $ref = 'TM-1', ?string $url = 'https://taskman.example/1', string $rate = '27.00'): array
    {
        return ['position' => 1, 'title' => 'T', 'task_ref' => $ref, 'portal_url' => $url, 'estimated_hours' => $est, 'hours' => $hours, 'rate' => $rate, 'amount' => '1.00'];
    }

    public function testBilledOfEstimatedPerLanguage(): void
    {
        foreach (['sk' => ' h', 'cs' => ' h', 'en' => ' h', 'pl' => ' godz.'] as $lang => $unit) {
            $rows = ReportRows::build([$this->line()], ReportRows::labels($lang), $lang)['rows'];
            $sep  = $lang === 'en' ? ' of ' : ' z ';

            $this->assertSame(PdfFormat::hours('16.00', $lang) . $sep . PdfFormat::hours('40.00', $lang) . $unit, $rows[0]['billed']);
        }
    }

    public function testBilledWithoutEstimate(): void
    {
        $rows = ReportRows::build([$this->line('16.00', null)], ReportRows::labels('sk'), 'sk')['rows'];

        $this->assertSame(PdfFormat::hours('16.00', 'sk') . ' h', $rows[0]['billed']);
        $this->assertNull($rows[0]['estimated']);
    }

    public function testPortalLabelAndUrlSafety(): void
    {
        $l = ReportRows::labels('sk');

        $this->assertSame('TM-1', ReportRows::build([$this->line()], $l, 'sk')['rows'][0]['portal_label']);

        $long = ReportRows::build([$this->line(ref: null, url: 'https://taskman.example/very/long/path/to/some/task/12345')], $l, 'sk')['rows'][0];
        $this->assertSame('taskman.example/very/long/path/to/some/t…', $long['portal_label']);
        $this->assertSame('https://taskman.example/very/long/path/to/some/task/12345', $long['portal_url']);

        $bad = ReportRows::build([$this->line(url: 'javascript:x')], $l, 'sk')['rows'][0];
        $this->assertNull($bad['portal_url']);
    }

    public function testUniformRate(): void
    {
        $l = ReportRows::labels('sk');

        $this->assertSame('27.00', ReportRows::build([$this->line(), $this->line()], $l, 'sk')['uniform_rate']);
        $this->assertNull(ReportRows::build([$this->line(), $this->line(rate: '30.00')], $l, 'sk')['uniform_rate']);
    }
}
