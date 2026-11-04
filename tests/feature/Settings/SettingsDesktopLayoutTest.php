<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Platform\FrozenClock;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AuthTestCase;

/**
 * PC rozloženie nastavení a Fakturácie mesiaca (UI-03, D-15).
 *
 * @internal
 */
#[Group('feature')]
final class SettingsDesktopLayoutTest extends AuthTestCase
{
    private const VIEWS = [
        'invoicing/_fx_card', 'settings/_devices', 'settings/clients/form', 'settings/clients/index',
        'settings/entities/_bank_accounts', 'settings/entities/_end_modal', 'settings/entities/new',
        'settings/entities/version_new', 'settings/legal_texts/index', 'settings/limits/index', 'settings/totp_setup',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-11-04T10:00:00+01:00'));
        session()->set('last_activity', service('clock')->now()->getTimestamp());
        $this->withSession();
    }

    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    #[DataProvider('providePageHasWidthContainer')]
    public function testPageHasWidthContainer(string $url): void
    {
        $admin = $this->createUser('owner@example.test', 'admin');
        $this->enrollTotp($admin);
        $this->loginAs($admin);

        $result = $this->get($url);
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $this->assertMatchesRegularExpression('/work-(narrow|wide|table)/', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePageHasWidthContainer(): iterable
    {
        foreach (['/settings/entities', '/settings/clients', '/settings/clients/new', '/settings/legal-texts', '/settings/limits', '/settings/email-templates', '/settings/security', '/settings/fx', '/invoices/new'] as $url) {
            yield $url => [$url];
        }
    }

    public function testFullWidthButtonsAreAutoOnDesktop(): void
    {
        $bad = [];

        foreach (self::VIEWS as $view) {
            $src = (string) file_get_contents(APPPATH . 'Views/' . $view . '.php');

            if (preg_match_all('/class="[^"]*\bbtn\b[^"]*\bw-100\b[^"]*"/', $src, $m) > 0) {
                foreach ($m[0] as $cls) {
                    if (! str_contains($cls, 'w-sm-auto') && ! str_contains($cls, 'w-lg-auto')) {
                        $bad[] = $view . ': ' . $cls;
                    }
                }
            }
        }

        $this->assertSame([], $bad);
    }
}
