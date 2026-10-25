<?php

declare(strict_types=1);

namespace Tests\Feature\Master;

use App\Libraries\Platform\FrozenClock;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;
use Tests\Support\MasterFixtures;

/**
 * @internal
 */
#[Group('feature')]
final class LegalProfileSectionTest extends AuthTestCase
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

    private function section(string $html): string
    {
        $start = strpos($html, 'id="entity-legal"');
        $this->assertNotFalse($start);
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end - $start);
    }

    public function testPlEntityShowsLegalProfile(): void
    {
        $id      = $this->createEntity('PL');
        $html    = $this->body($this->get('/settings/entities/' . $id));
        $section = $this->section($html);

        $this->assertStringContainsString(esc(lang('Entities.legalTitle')), $section);
        $this->assertStringContainsString('ART_113_1', $section);
        $this->assertStringContainsString('ART_28B_RC', $section);
        $this->assertStringContainsString('art. 113 ust. 1', $section);
        $this->assertStringContainsString(esc(lang('Entities.legalProvisional')), $section);
        $this->assertStringContainsString(esc(lang('Entities.legalHint')), $section);
        $this->assertStringNotContainsStringIgnoringCase('<form', $section);
        $this->assertStringNotContainsStringIgnoringCase('/edit', $section);
    }

    public function testSkEntityShowsNoCodeDash(): void
    {
        $id      = $this->createEntity('SK');
        $section = $this->section($this->body($this->get('/settings/entities/' . $id)));

        $this->assertStringContainsString('Nie som platiteľ DPH.', $section);
        $this->assertStringContainsString(esc(lang('Entities.emptyValue')), $section);
        $this->assertStringNotContainsString('<code>', $section);
    }
}
