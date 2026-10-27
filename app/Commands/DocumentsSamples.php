<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Invoicing\RendererRegistry;
use App\Libraries\Invoicing\Samples\SampleSnapshots;
use App\Libraries\Master\LegalProfileResolver;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Vygeneruje vzorove PDF faktury a vykazu pre kazdy pravny profil a jazyk (D-23).
 * Iba cita (SELECT zneni zakonnych textov), nic nezapisuje do DB.
 *
 * @internal
 */
final class DocumentsSamples extends BaseCommand
{
    private const LANGUAGES = ['sk', 'cs', 'pl', 'en'];

    /**
     * Predvoleny jazyk dokladu profilu (zhodny so SampleSnapshots).
     */
    private const PROFILE_LANGUAGE = ['sk' => 'sk', 'cz' => 'cs', 'pl' => 'pl', 'pl_eu_b2b' => 'sk', 'pl_non_eu' => 'en'];

    protected $group       = 'Documents';
    protected $name        = 'documents:samples';
    protected $description = 'Vygeneruje vzorové PDF faktúry a výkazu (označené VZOR) pre každý profil a jazyk, bez zápisu do databázy.';
    protected $usage       = 'documents:samples [--profile <kľúč>] [--language <sk|cs|pl|en>] [--out <podadresár>]';
    protected $options     = [
        '--profile'  => 'Len jeden profil: sk, cz, pl, pl_eu_b2b, pl_non_eu (predvolene všetky)',
        '--language' => 'Len jeden jazyk dokladu: sk, cs, pl, en (predvolene jazyk profilu a en)',
        '--out'      => 'Podadresár vo writable/samples/ (predvolene priamo writable/samples/)',
    ];

    public function run(array $params): int
    {
        $profile  = $this->opt($params, 'profile');
        $language = $this->opt($params, 'language');
        $out      = $this->opt($params, 'out');

        if (
            ($profile !== null && ! in_array($profile, SampleSnapshots::PROFILES, true))
            || ($language !== null && ! in_array($language, self::LANGUAGES, true))
            || ($out !== null && preg_match('/^[a-z0-9_-]{1,40}$/D', $out) !== 1)
        ) {
            CLI::error('Neplatný vstup. Použitie: ' . $this->usage);

            return EXIT_ERROR;
        }

        $dir = WRITEPATH . 'samples/' . ($out === null ? '' : $out . '/');

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            CLI::error('Nepodarilo sa vytvoriť adresár ' . $dir);

            return EXIT_ERROR;
        }

        $resolver = $this->resolver();
        $registry = new RendererRegistry();
        $written  = [];

        try {
            foreach ($profile === null ? SampleSnapshots::PROFILES : [$profile] as $p) {
                $languages = $language !== null ? [$language] : array_values(array_unique([self::PROFILE_LANGUAGE[$p], 'en']));

                foreach ($languages as $lang) {
                    $snapshot = $this->snapshot($p, $lang, $resolver);
                    $base     = $dir . $p . '-' . $lang . '-';

                    foreach ([['faktura', 'template_version'], ['vykaz', 'report_template_version']] as [$suffix, $versionKey]) {
                        $pdf = $registry->for((string) $snapshot[$versionKey])->render($snapshot);
                        file_put_contents($base . $suffix . '.pdf', $pdf->bytes);
                        $written[] = $base . $suffix . '.pdf';
                    }
                }
            }
        } catch (Throwable $e) {
            CLI::error('Generovanie vzoriek zlyhalo: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        foreach ($written as $file) {
            CLI::write($file);
        }

        CLI::write('Hotovo: ' . count($written) . ' súborov. Sú to VZORY s fixtúrnymi údajmi, nie doklady.', 'green');

        return EXIT_SUCCESS;
    }

    private function resolver(): ?LegalProfileResolver
    {
        try {
            $r = new LegalProfileResolver();
            $r->profile('SK', 'sk_non_vat', 'domestic');

            return $r;
        } catch (Throwable) {
            CLI::write('Upozornenie: databáza nie je dostupná, použijú sa seed znenia zákonných textov.', 'yellow');

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $profile, string $language, ?LegalProfileResolver $resolver): array
    {
        if ($resolver !== null) {
            try {
                return SampleSnapshots::invoice($profile, $language, [], $resolver);
            } catch (Throwable) {
                CLI::write(sprintf('Upozornenie: v databáze chýba znenie pre %s / %s, použije sa seed.', $profile, $language), 'yellow');
            }
        }

        return SampleSnapshots::invoice($profile, $language);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function opt(array $params, string $name): ?string
    {
        $v = $params[$name] ?? CLI::getOption($name);

        return is_string($v) ? $v : null;
    }
}
