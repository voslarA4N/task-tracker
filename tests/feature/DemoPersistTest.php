<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('feature')]
final class DemoPersistTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTPS', 'on');
        Database::connect('tests_owner', false)->query('TRUNCATE demo_calculations RESTART IDENTITY');
    }

    private function rowCount(): int
    {
        return (int) Database::connect('tests_owner', false)->query('SELECT count(*) AS c FROM demo_calculations')->getRow()->c;
    }

    /**
     * @param array<string, string> $post
     */
    private function store(array $post): TestResponse
    {
        return $this->withHeaders(['HX-Request' => 'true', 'X-CSRF-TOKEN' => csrf_hash()])
            ->call('post', '/demo/calculations', $post);
    }

    public function testStoreListsRowsAndTotal(): void
    {
        $first = $this->store(['hours' => '1,25', 'rate' => '27']);
        $first->assertStatus(200);
        $body = (string) $first->response()->getBody();
        $this->assertStringContainsString('33.75', $body);
        $this->assertStringNotContainsString('<html', $body);

        $second = $this->store(['hours' => '2', 'rate' => '10.50']);
        $second->assertStatus(200);
        $body = (string) $second->response()->getBody();
        $this->assertStringContainsString('21.00', $body);
        $this->assertStringContainsString('54.75', $body);

        $page = (string) $this->withHeaders(['HX-Request' => 'true', 'HX-Boosted' => 'true'])->get('/demo')->response()->getBody();
        $this->assertStringContainsString('<html', $page);
        $this->assertStringContainsString('33.75', $page);
        $this->assertStringContainsString('21.00', $page);
        $this->assertSame(2, $this->rowCount());
    }

    public function testInvalidInputGives422AndStoresNothing(): void
    {
        $this->store(['hours' => 'abc', 'rate' => '27'])->assertStatus(422);
        $this->assertSame(0, $this->rowCount());
    }
}
