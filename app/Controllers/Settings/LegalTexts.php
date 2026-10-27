<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
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
        $groups      = (new LegalTextService())->listGrouped();
        $provisional = 0;

        foreach ($groups as $group) {
            foreach ($group['texts'] as $text) {
                if ($text['provisional'] === true) {
                    $provisional++;
                }
            }
        }

        return view('settings/legal_texts/index', ['groups' => $groups, 'provisionalCount' => $provisional]);
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
