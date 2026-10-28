<?php

declare(strict_types=1);

namespace Tests\Integration\Master;

use App\Libraries\Master\LegalProfileResolver;
use App\Libraries\Master\LegalTextMissing;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PgTestCase;

/**
 * @internal
 */
#[Group('integration')]
final class LegalProfileTest extends PgTestCase
{
    private function resolver(): LegalProfileResolver
    {
        return new LegalProfileResolver($this->rw());
    }

    private function total(string $table): int
    {
        return (int) $this->owner()->query('SELECT count(*) AS c FROM ' . $table)->getRow()->c;
    }

    public function testSeedCounts(): void
    {
        $this->assertSame(6, $this->total('legal_profiles'));
        $this->assertSame(16, $this->total('legal_texts'));
    }

    public function testExemptionCodes(): void
    {
        $r = $this->resolver();
        $this->assertSame('ART_113_1', $r->exemptionCode('PL', 'pl_exempt_113_1', 'domestic'));
        $this->assertSame('ART_113_9', $r->exemptionCode('PL', 'pl_exempt_113_9', 'domestic'));
        $this->assertSame('ART_28B_RC', $r->exemptionCode('PL', 'pl_exempt_113_1', 'eu_b2b'));
        $this->assertNull($r->exemptionCode('SK', 'sk_non_vat', 'domestic'));
    }

    public function testVatCodes(): void
    {
        $r = $this->resolver();
        $this->assertSame('zw', $r->profile('PL', 'pl_exempt_113_1', 'domestic')['vat_code']);
        $this->assertSame('zw', $r->profile('PL', 'pl_exempt_113_9', 'non_eu')['vat_code']);
        $this->assertSame('np', $r->profile('PL', 'pl_exempt_113_1', 'eu_b2b')['vat_code']);
        $this->assertSame('np', $r->profile('SK', 'sk_non_vat', 'domestic')['vat_code']);
        $this->assertSame('np', $r->profile('CZ', 'cz_non_vat', 'eu_b2b')['vat_code']);
    }

    public function testVatCodeReadOnlyForRw(): void
    {
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE legal_profiles SET vat_code = 'x'"));
    }

    public function testTextExactAndProvisional(): void
    {
        $t = $this->resolver()->text('SK', 'sk_non_vat', 'domestic', 'sk', 'vat_note');
        $this->assertSame('Nie som platiteľ DPH.', $t['body']);
        $this->assertTrue($t['provisional']);
        $this->assertSame('sk', $t['language']);
    }

    public function testTextFallsBackToEnglish(): void
    {
        $t = $this->resolver()->text('SK', 'sk_non_vat', 'domestic', 'pl', 'vat_note');
        $this->assertSame('en', $t['language']);
        $this->assertSame('Not a VAT payer.', $t['body']);
    }

    public function testTextBillingModeSpecificAndNullFallback(): void
    {
        $r = $this->resolver();
        $this->assertSame('Odwrotne obciążenie.', $r->text('PL', 'pl_exempt_113_1', 'eu_b2b', 'pl', 'vat_note')['body']);
        $this->assertStringContainsString('art. 113 ust. 1', $r->text('PL', 'pl_exempt_113_1', 'non_eu', 'pl', 'vat_note')['body']);
    }

    public function testReverseChargeCodeNeverPairsWithGenericExemptionText(): void
    {
        // Jazyk má všeobecný text (billing_mode NULL), ale nie text pre eu_b2b: musí sa použiť eu_b2b text v en,
        // nie všeobecné znenie art. 113 vedľa kódu ART_28B_RC (WR-11).
        $this->owner()->query(
            "INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at)
             VALUES ('PL', 'pl_exempt_113_1', NULL, 'cs', 'vat_note', 'Prodávající je osvobozen podle čl. 113 odst. 1.', true, now())",
        );

        try {
            $r = $this->resolver();
            $this->assertSame('ART_28B_RC', $r->exemptionCode('PL', 'pl_exempt_113_1', 'eu_b2b'));

            $t = $r->text('PL', 'pl_exempt_113_1', 'eu_b2b', 'cs', 'vat_note');
            $this->assertSame('en', $t['language']);
            $this->assertSame('Reverse charge.', $t['body']);

            $this->assertStringContainsString('čl. 113', $r->text('PL', 'pl_exempt_113_1', 'domestic', 'cs', 'vat_note')['body']);
        } finally {
            $this->owner()->query("DELETE FROM legal_texts WHERE country = 'PL' AND language = 'cs'");
        }
    }

    public function testMissingKeyThrows(): void
    {
        $this->expectException(LegalTextMissing::class);
        $this->resolver()->text('SK', 'sk_non_vat', 'domestic', 'sk', 'foo_bar');
    }

    public function testMissingProfileThrows(): void
    {
        $this->expectException(LegalTextMissing::class);
        $this->resolver()->profile('CZ', 'pl_exempt_113_1', 'domestic');
    }

    public function testAppRwCannotWriteProfilesOrDeleteTexts(): void
    {
        $this->assertSame(16, (int) $this->rw()->query('SELECT count(*) AS c FROM legal_texts')->getRow()->c);
        $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE legal_profiles SET exemption_code = 'X_Y_Z'"));
        $this->assertPermissionDenied(fn () => $this->rw()->query(
            "INSERT INTO legal_profiles (country, vat_regime, billing_mode, exemption_code, updated_at) VALUES ('SK','sk_non_vat','eu_b2b',NULL, now())",
        ));
        $this->assertPermissionDenied(fn () => $this->rw()->query('DELETE FROM legal_texts'));
    }

    public function testTextResolverReturnsRevision(): void
    {
        $this->assertSame(1, $this->resolver()->text('SK', 'sk_non_vat', 'domestic', 'sk', 'vat_note')['revision']);
    }

    public function testNullBillingModeIsUnique(): void
    {
        $this->expectException(DatabaseException::class);
        $this->owner()->query("INSERT INTO legal_profiles (country, vat_regime, billing_mode, exemption_code, updated_at) VALUES ('SK','sk_non_vat',NULL,NULL, now())");
    }

    public function testOptionalTextIsNullWhenMissingAndFoundWhenPresent(): void
    {
        $r = $this->resolver();
        $this->assertNull($r->optionalText('SK', 'sk_non_vat', 'domestic', 'sk', 'invoice_note'));

        $this->owner()->query("INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at) VALUES ('SK', 'sk_non_vat', NULL, 'sk', 'invoice_note', 'Predvolena poznamka', true, now())");

        try {
            $this->assertSame(['body' => 'Predvolena poznamka', 'language' => 'sk', 'provisional' => true, 'revision' => 1, 'billing_mode' => null], $this->resolver()->optionalText('SK', 'sk_non_vat', 'domestic', 'sk', 'invoice_note'));
        } finally {
            $this->owner()->query("DELETE FROM legal_texts WHERE key = 'invoice_note'");
        }
    }

    public function testGeneralProfileUsesClientModeVariantThenFallsBack(): void
    {
        $r      = $this->resolver();
        $before = $r->text('PL', 'pl_exempt_113_1', 'non_eu', 'pl', 'vat_note');
        $this->assertNull($before['billing_mode']);

        $this->owner()->query(
            "INSERT INTO legal_texts (country, vat_regime, billing_mode, language, key, body, provisional, updated_at)
             VALUES ('PL', 'pl_exempt_113_1', 'non_eu', 'pl', 'vat_note', 'Miejsce świadczenia poza terytorium kraju.', true, now())",
        );

        try {
            $t = $r->text('PL', 'pl_exempt_113_1', 'non_eu', 'pl', 'vat_note');
            $this->assertSame('Miejsce świadczenia poza terytorium kraju.', $t['body']);
            $this->assertSame('non_eu', $t['billing_mode']);
            // iny režim klienta variant nevidí
            $this->assertNull($r->text('PL', 'pl_exempt_113_1', 'domestic', 'pl', 'vat_note')['billing_mode']);
            // presný profil eu_b2b nikdy nepoužije non_eu ani všeobecné znenie
            $this->assertSame('Odwrotne obciążenie.', $r->text('PL', 'pl_exempt_113_1', 'eu_b2b', 'pl', 'vat_note')['body']);
        } finally {
            $this->owner()->query("DELETE FROM legal_texts WHERE billing_mode = 'non_eu'");
        }
    }

    public function testMissingLanguageFallsBackToEnglishForSkProfile(): void
    {
        $t = $this->resolver()->text('SK', 'sk_non_vat', 'domestic', 'cs', 'vat_note');
        $this->assertSame('en', $t['language']);
        $this->assertSame('Not a VAT payer.', $t['body']);
    }
}
