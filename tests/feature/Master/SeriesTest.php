<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class SeriesTest extends AuthTestCase
{
    use MasterFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
        $user = $this->createUser('owner@example.test');
        $this->enrollTotp($user);
        $this->loginAs($user);
    }

    protected function tearDown(): void
    {
        $this->resetMasterTables();
        Services::reset();
        parent::tearDown();
    }

    private function body(TestResponse $result): string
    {
        return (string) $result->response()->getBody();
    }

    /**
     * @param array<string, string> $data
     */
    private function send(string $path, array $data = []): TestResponse
    {
        $this->withSession();

        return $this->call('post', $path, $data + [csrf_token() => csrf_hash()]);
    }

    public function testDetailShowsSeriesSection(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->get('/settings/entities/' . $id));

        $this->assertStringContainsString(esc(lang('Entities.seriesTitle')), $html);
        $this->assertStringContainsString('RRRRMM###', $html);
        $this->assertStringContainsString('DB-RRRRMM###', $html);
        $this->assertStringContainsString('202610001', $html);
        $this->assertStringContainsString('DB-202610001', $html);
        $this->assertStringContainsString(esc(lang('Entities.seriesResetMonthly')), $html);
        $this->assertStringContainsString('series/invoice/edit', $html);
        $this->assertStringContainsString('series/credit_note/edit', $html);
    }

    public function testEditFormHasFieldsAndLivePreview(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->get('/settings/entities/' . $id . '/series/invoice/edit'));

        $this->assertStringContainsString('name="format"', $html);
        $this->assertStringContainsString('name="initial_number"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('hx-get="/settings/entities/' . $id . '/series/invoice/preview"', $html);
        $this->assertStringContainsString('id="series-preview"', $html);
        $this->assertStringContainsString(esc(lang('Entities.seriesTokensTitle')), $html);
    }

    public function testUnknownKindIs404(): void
    {
        $id = $this->createEntity('SK');

        foreach (['/settings/entities/' . $id . '/series/foo/edit', '/settings/entities/' . $id . '/series/foo/preview'] as $path) {
            try {
                $this->get($path);
                $this->fail('Expected PageNotFoundException (404)');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->send('/settings/entities/' . $id . '/series/foo', ['format' => 'RRRR###', 'initial_number' => '1']);
            $this->fail('Expected PageNotFoundException (404)');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPreviewFragmentYearly(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/settings/entities/' . $id . '/series/invoice/preview', ['format' => 'RR/####', 'initial_number' => '1']));

        $this->assertStringContainsString('26/0001', $html);
        $this->assertStringContainsString('26/0002', $html);
        $this->assertStringContainsString(esc(lang('Entities.seriesResetYearly')), $html);
        $this->assertStringContainsString('id="series-preview"', $html);
    }

    public function testPreviewFragmentInvalid(): void
    {
        $id   = $this->createEntity('SK');
        $html = $this->body($this->withHeaders(['HX-Request' => 'true'])->get('/settings/entities/' . $id . '/series/invoice/preview', ['format' => '###', 'initial_number' => '1']));

        $this->assertStringContainsString(esc(lang('Entities.errorSeriesFormat')), $html);
        $this->assertStringContainsString('invalid-feedback', $html);
    }

    public function testUpdateValidRedirectsWithFlash(): void
    {
        $id = $this->createEntity('SK');
        $this->send('/settings/entities/' . $id . '/series/invoice', ['format' => 'FA-RRRR-####', 'initial_number' => '5'])
            ->assertStatus(302)
            ->assertRedirectTo('/settings/entities/' . $id);

        $row = $this->owner()->query("SELECT format, initial_number FROM invoice_series WHERE entity_id = ? AND kind = 'invoice'", [$id])->getRow();
        $this->assertSame('FA-RRRR-####', $row->format);
        $this->assertSame(5, (int) $row->initial_number);

        $this->withSession();
        $html = $this->body($this->get('/settings/entities/' . $id));
        $this->assertStringContainsString(esc(lang('Entities.seriesSaved')), $html);
        $this->assertStringContainsString('FA-2026-0005', $html);
    }

    public function testSameFormatAsCreditNoteIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->send('/settings/entities/' . $id . '/series/invoice', ['format' => 'DB-RRRRMM###', 'initial_number' => '1']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorSeriesSame')), $this->body($result));
    }

    public function testInvalidFormatIs422AndKeepsInput(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->send('/settings/entities/' . $id . '/series/invoice', ['format' => 'FA###', 'initial_number' => '1']);

        $result->assertStatus(422);
        $html = $this->body($result);
        $this->assertStringContainsString(esc(lang('Entities.errorSeriesFormat')), $html);
        $this->assertStringContainsString('value="FA###"', $html);
        $this->assertSame('RRRRMM###', $this->owner()->query("SELECT format FROM invoice_series WHERE entity_id = ? AND kind = 'invoice'", [$id])->getRow()->format);
    }

    public function testInvalidInitialIs422(): void
    {
        $id     = $this->createEntity('SK');
        $result = $this->send('/settings/entities/' . $id . '/series/invoice', ['format' => 'RRRRMM###', 'initial_number' => '1000']);

        $result->assertStatus(422);
        $this->assertStringContainsString(esc(lang('Entities.errorSeriesInitial')), $this->body($result));
    }
}
