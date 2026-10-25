<?php

declare(strict_types=1);

namespace Tests\Unit\Work;

use App\Libraries\Work\TaskInput;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('unit')]
final class TaskInputTest extends CIUnitTestCase
{
    /**
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    private function valid(array $over = []): array
    {
        return $over + [
            'client_id' => '3',
            'url'       => 'https://taskman.example/?todo=1',
            'title'     => 'Oprava exportu',
            'hours'     => '8,5',
            'rate'      => '27',
            'note'      => '',
        ];
    }

    public function testValidInputNormalizes(): void
    {
        $this->assertSame([], TaskInput::validate($this->valid()));
        $n = TaskInput::normalize($this->valid());
        $this->assertSame('8.50', $n['hours']);
        $this->assertSame('27.00', $n['rate']);
        $this->assertSame(3, $n['client_id']);
        $this->assertNull($n['note']);
        $this->assertSame('https://taskman.example/?todo=1', $n['url']);
        $this->assertNull(TaskInput::normalize($this->valid(['url' => '']))['url']);
    }

    #[DataProvider('provideBadHours')]
    public function testBadHours(string $hours): void
    {
        $this->assertArrayHasKey('hours', TaskInput::validate($this->valid(['hours' => $hours])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBadHours(): iterable
    {
        yield 'zero' => ['0'];

        yield 'negative' => ['-1'];

        yield 'three decimals' => ['8.555'];

        yield 'text' => ['abc'];

        yield 'empty' => [''];
    }

    public function testBadRate(): void
    {
        $this->assertArrayHasKey('rate', TaskInput::validate($this->valid(['rate' => '27.555'])));
        $this->assertArrayHasKey('rate', TaskInput::validate($this->valid(['rate' => ''])));
    }

    #[DataProvider('provideBadUrl')]
    public function testBadUrl(string $url): void
    {
        $this->assertArrayHasKey('url', TaskInput::validate($this->valid(['url' => $url])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBadUrl(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];

        yield 'ftp' => ['ftp://x'];

        yield 'no host' => ['https://'];

        yield 'digits without template' => ['81252'];

        yield 'too long' => ['https://example.test/' . str_repeat('a', 2000)];
    }

    public function testDigitsAllowedWithTemplate(): void
    {
        $this->assertSame([], TaskInput::validate($this->valid(['url' => '81252']), 'https://taskman.example/?todo={id}'));
        $this->assertArrayHasKey('url', TaskInput::validate($this->valid(['url' => '81252'])));
    }

    public function testTitleAndNote(): void
    {
        $this->assertArrayHasKey('title', TaskInput::validate($this->valid(['title' => '   '])));
        $this->assertArrayHasKey('title', TaskInput::validate($this->valid(['title' => str_repeat('a', 301)])));
        $this->assertArrayHasKey('note', TaskInput::validate($this->valid(['note' => str_repeat('a', 2001)])));
        $this->assertArrayHasKey('client_id', TaskInput::validate($this->valid(['client_id' => 'x'])));
    }

    public function testFromPostIgnoresForgedFields(): void
    {
        $values = TaskInput::fromPost($this->request([
            'client_id'  => '3', 'title' => ' T ', 'hours' => '1', 'rate' => '2', 'url' => '', 'note' => '',
            'amount'     => '999', 'status' => 'done', 'invoice_id' => '5', 'done_at' => '2026-01-01', 'import_key' => 'x',
        ]));

        $this->assertSame(['client_id', 'url', 'title', 'hours', 'rate', 'note'], array_keys($values));
        $this->assertSame('T', $values['title']);
    }

    /**
     * @param array<string, string> $post
     */
    private function request(array $post): IncomingRequest
    {
        $config  = new App();
        $request = new IncomingRequest($config, new SiteURI($config), null, new UserAgent());
        $request->setGlobal('post', $post);

        return $request;
    }
}
