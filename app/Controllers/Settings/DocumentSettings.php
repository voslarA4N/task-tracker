<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Invoicing\DocumentSettingsRejected;
use App\Libraries\Invoicing\DocumentSettingsService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Nastavenie dokladov: lehota pregenerovania PDF faktury (D-13).
 */
final class DocumentSettings extends BaseController
{
    public function index(): string
    {
        return view('settings/documents/index', $this->data(null, null, false, false));
    }

    public function update(): RedirectResponse|ResponseInterface
    {
        $rawHours = $this->request->getPost('regen_invoice_hours');
        $hours    = is_string($rawHours) ? $rawHours : '';
        $revision = (int) $this->request->getPost('revision');

        try {
            (new DocumentSettingsService())->update($hours, $revision);
        } catch (DocumentSettingsRejected $e) {
            if ($e->reason === 'stale') {
                return $this->response->setStatusCode(409)->setBody(view('settings/documents/index', $this->data(null, null, true, false)));
            }

            return $this->response->setStatusCode(422)->setBody(view('settings/documents/index', $this->data($hours, $revision, false, true)));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('DocumentSettings.saved')]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/documents')->setBody('');
        }

        return redirect()->to('/settings/documents');
    }

    /**
     * @return array<string, mixed>
     */
    private function data(?string $draft, ?int $draftRevision, bool $stale, bool $invalid): array
    {
        $current = (new DocumentSettingsService())->current();

        return [
            'hours'    => $draft ?? (string) $current['regen_invoice_hours'],
            'revision' => $draftRevision ?? $current['revision'],
            'stale'    => $stale,
            'invalid'  => $invalid,
        ];
    }
}
