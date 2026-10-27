<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\BillingMode;
use App\Libraries\Master\DocLanguage;
use App\Libraries\Master\LegalTextRejected;
use App\Libraries\Master\LegalTextService;
use App\Libraries\Platform\DbBool;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Zakonne texty (SET-01, D-13, D-14): zoznam a uprava znenia. Mazanie sa neponuka.
 */
final class LegalTexts extends BaseController
{
    public function index(): string
    {
        $service     = new LegalTextService();
        $groups      = $service->listGrouped();
        $provisional = 0;

        foreach ($groups as &$group) {
            $group['effective']   = $this->effectiveRows($service, $group);
            $group['noteMissing'] = ! array_filter($group['texts'], static fn (array $t): bool => $t['key'] === 'invoice_note');

            foreach ($group['texts'] as $text) {
                if ($text['provisional'] === true) {
                    $provisional++;
                }
            }
        }
        unset($group);

        return view('settings/legal_texts/index', ['groups' => $groups, 'provisionalCount' => $provisional]);
    }

    public function new(): string
    {
        $q = fn (string $name): string => is_string($v = $this->request->getGet($name)) ? $v : '';

        return view('settings/legal_texts/new', $this->newData([
            'profile'      => $this->validProfile($q('country'), $q('vat_regime')) ? $q('country') . '|' . $q('vat_regime') : '',
            'key'          => in_array($q('key'), LegalTextService::KEYS, true) ? $q('key') : 'vat_note',
            'language'     => $this->enumValue(DocLanguage::tryFrom($q('language'))),
            'billing_mode' => $this->enumValue(BillingMode::tryFrom($q('billing_mode'))),
        ], null));
    }

    public function create(): RedirectResponse|ResponseInterface
    {
        $profile = $this->postString('profile');
        $values  = [
            'profile'      => $profile,
            'key'          => $this->postString('key'),
            'language'     => $this->postString('language'),
            'billing_mode' => $this->postString('billing_mode'),
            'body'         => $this->postString('body'),
            'confirmed'    => $this->postString('confirmed') === '1',
        ];
        [$country, $regime] = array_pad(explode('|', $profile, 2), 2, '');

        try {
            (new LegalTextService())->create(
                $country,
                $regime,
                $values['billing_mode'] === '' ? null : $values['billing_mode'],
                $values['language'],
                $values['key'],
                $values['body'],
                ! $values['confirmed'],
            );
        } catch (LegalTextRejected $e) {
            $error = match (true) {
                $e->reason === 'exists'                                        => lang('LegalTexts.errorExists'),
                $e->reason === 'too_long' && $values['key'] === 'invoice_note' => lang('LegalTexts.errorNoteTooLong'),
                $e->reason === 'invalid'                                       => lang('LegalTexts.errorInvalid'),
                default                                                        => lang('LegalTexts.errorBody'),
            };

            return $this->response->setStatusCode(422)->setBody(view('settings/legal_texts/new', $this->newData($values, $error)));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('LegalTexts.created')]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/legal-texts')->setBody('');
        }

        return redirect()->to('/settings/legal-texts');
    }

    public function edit(int $id): string
    {
        $text = $this->textOr404($id);

        return view('settings/legal_texts/edit', $this->formData($text, (string) $text['body'], $this->isProvisional($text), (int) $text['revision'], null, false));
    }

    public function update(int $id): RedirectResponse|ResponseInterface
    {
        $text        = $this->textOr404($id);
        $body        = $this->postString('body');
        $provisional = $this->postString('confirmed') !== '1';
        $revision    = (int) $this->postString('revision');

        try {
            $newRevision = (new LegalTextService())->update($id, $body, $provisional, $revision);
        } catch (LegalTextRejected $e) {
            if ($e->reason === 'not_found') {
                throw PageNotFoundException::forPageNotFound(lang('LegalTexts.notFound'));
            }

            $stale  = $e->reason === 'stale';
            $error  = $stale ? null : lang($e->reason === 'too_long' && $text['key'] === 'invoice_note' ? 'LegalTexts.errorNoteTooLong' : 'LegalTexts.errorBody');
            $status = $stale ? 409 : 422;

            return $this->response->setStatusCode($status)->setBody(view('settings/legal_texts/edit', $this->formData($text, $body, $provisional, $revision, $error, $stale)));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('LegalTexts.saved', [$newRevision])]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/legal-texts')->setBody('');
        }

        return redirect()->to('/settings/legal-texts');
    }

    /**
     * Pre kazdy jazyk a rezim zisti, ktore znenie sa realne pouzije; vrati len riadky bez vlastneho znenia.
     * Rezimy: vseobecny a tie, ktore maju profil alebo vlastne znenie.
     *
     * @param array<string, mixed> $group
     *
     * @return list<array<string, mixed>>
     */
    private function effectiveRows(LegalTextService $service, array $group): array
    {
        $country = (string) $group['country'];
        $regime  = (string) $group['vat_regime'];
        $modes   = [null];

        foreach ($group['profiles'] as $p) {
            $modes[] = $p['billing_mode'];
        }

        foreach ($group['texts'] as $t) {
            $modes[] = $t['billing_mode'] === null ? null : (string) $t['billing_mode'];
        }

        $rows = [];

        foreach (array_unique($modes, SORT_REGULAR) as $mode) {
            foreach (DocLanguage::cases() as $lang) {
                $eff = $service->effective($country, $regime, $mode, $lang->value, 'vat_note');

                if ($eff !== null && ! $eff['fallback']) {
                    continue;
                }

                $rows[] = [
                    'language'  => $lang->value,
                    'mode'      => (string) $mode,
                    'effective' => $eff,
                    'newUrl'    => '/settings/legal-texts/new?' . http_build_query(['country' => $country, 'vat_regime' => $regime, 'language' => $lang->value, 'key' => 'vat_note', 'billing_mode' => (string) $mode]),
                ];
            }
        }

        return $rows;
    }

    private function enumValue(BillingMode|DocLanguage|null $case): string
    {
        return $case === null ? '' : $case->value;
    }

    private function validProfile(string $country, string $regime): bool
    {
        foreach ((new LegalTextService())->listGrouped() as $g) {
            if ($g['country'] === $country && $g['vat_regime'] === $regime && $g['profiles'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function newData(array $values, ?string $error): array
    {
        $profiles = [];

        foreach ((new LegalTextService())->listGrouped() as $g) {
            if ($g['profiles'] !== []) {
                $profiles[] = ['value' => $g['country'] . '|' . $g['vat_regime'], 'country' => $g['country'], 'regime' => $g['vat_regime']];
            }
        }

        return ['values' => $values + ['body' => '', 'confirmed' => false], 'profiles' => $profiles, 'error' => $error];
    }

    /**
     * @param array<string, mixed> $text
     *
     * @return array<string, mixed>
     */
    private function formData(array $text, string $body, bool $provisional, int $revision, ?string $error, bool $stale): array
    {
        return ['text' => $text, 'body' => $body, 'provisional' => $provisional, 'revision' => $revision, 'error' => $error, 'stale' => $stale];
    }

    /**
     * @param array<string, mixed> $text
     */
    private function isProvisional(array $text): bool
    {
        return DbBool::of($text['provisional']);
    }

    private function postString(string $name): string
    {
        $raw = $this->request->getPost($name);

        return is_string($raw) ? $raw : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function textOr404(int $id): array
    {
        $text = (new LegalTextService())->find($id);

        if ($text === null) {
            throw PageNotFoundException::forPageNotFound(lang('LegalTexts.notFound'));
        }

        return $text;
    }
}
