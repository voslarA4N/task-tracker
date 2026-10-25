<?php

declare(strict_types=1);

namespace App\Libraries\Master;

use App\Libraries\Platform\DbBool;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Citanie pravnych profilov a textov (SET-02, D-16, D-17).
 */
final class LegalProfileResolver
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Presna zhoda, potom billing_mode IS NULL, inak LegalTextMissing.
     *
     * @return array{country: string, vat_regime: string, billing_mode: ?string, exemption_code: ?string}
     */
    public function profile(string $country, string $vatRegime, string $billingMode): array
    {
        foreach ([$billingMode, null] as $mode) {
            $row = $this->db->query(
                'SELECT country, vat_regime, billing_mode, exemption_code FROM legal_profiles '
                . 'WHERE country = :c: AND vat_regime = :r: AND billing_mode IS NOT DISTINCT FROM CAST(:m: AS text)',
                ['c' => $country, 'r' => $vatRegime, 'm' => $mode],
            )->getRowArray();

            if ($row !== null) {
                return [
                    'country'        => (string) $row['country'],
                    'vat_regime'     => (string) $row['vat_regime'],
                    'billing_mode'   => $row['billing_mode'] === null ? null : (string) $row['billing_mode'],
                    'exemption_code' => $row['exemption_code'] === null ? null : (string) $row['exemption_code'],
                ];
            }
        }

        throw new LegalTextMissing(sprintf('No legal profile for %s / %s / %s', $country, $vatRegime, $billingMode));
    }

    public function exemptionCode(string $country, string $vatRegime, string $billingMode): ?string
    {
        return $this->profile($country, $vatRegime, $billingMode)['exemption_code'];
    }

    /**
     * Text vždy z toho istého riadku profilu ako kód oslobodenia (WR-11): mód sa určí cez profile()
     * (presná zhoda, inak NULL) a text sa hľadá len pre tento mód. Poradie: (jazyk, mód) -> (en, mód) -> výnimka.
     * Kód ART_28B_RC sa tak nikdy nespáruje so všeobecným znením "zwolniony ... art. 113".
     *
     * @return array{body: string, language: string, provisional: bool}
     */
    public function text(string $country, string $vatRegime, string $billingMode, string $language, string $key): array
    {
        $mode     = $this->profile($country, $vatRegime, $billingMode)['billing_mode'];
        $attempts = [[$language, $mode], ['en', $mode]];

        foreach ($attempts as [$lang, $mode]) {
            $row = $this->db->query(
                'SELECT body, language, provisional FROM legal_texts '
                . 'WHERE country = :c: AND vat_regime = :r: AND language = :l: AND key = :k: '
                . 'AND billing_mode IS NOT DISTINCT FROM CAST(:m: AS text)',
                ['c' => $country, 'r' => $vatRegime, 'l' => $lang, 'k' => $key, 'm' => $mode],
            )->getRowArray();

            if ($row !== null && (string) $row['body'] !== '') {
                return [
                    'body'        => (string) $row['body'],
                    'language'    => (string) $row['language'],
                    'provisional' => DbBool::of($row['provisional']),
                ];
            }
        }

        throw new LegalTextMissing(sprintf('No legal text %s for %s / %s / %s / %s', $key, $country, $vatRegime, $billingMode, $language));
    }
}
