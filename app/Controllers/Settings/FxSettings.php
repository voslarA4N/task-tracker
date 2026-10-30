<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Fx\FxSettingsRejected;
use App\Libraries\Fx\FxSettingsService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Nastavenia kurzu podla krajiny subjektu (CUR-05, D-05).
 */
final class FxSettings extends BaseController
{
    public function index(): string
    {
        return view('settings/fx/index', $this->data(null, false, [], null));
    }

    public function update(): RedirectResponse|ResponseInterface
    {
        $service = new FxSettingsService();
        $rows    = [];

        foreach (FxSettingsService::COUNTRIES as $country) {
            $rows[$country] = [
                'source'     => $this->field($country, 'source'),
                'date_basis' => $this->field($country, 'date_basis'),
                'day_rule'   => $this->field($country, 'day_rule'),
                'revision'   => (int) $this->field($country, 'revision'),
            ];
        }

        try {
            $service->updateAll($rows);
        } catch (FxSettingsRejected $e) {
            if ($e->reason === 'stale') {
                return $this->response->setStatusCode(409)->setBody(view('settings/fx/index', $this->data(null, true, [], null)));
            }

            return $this->response->setStatusCode(422)->setBody(view('settings/fx/index', $this->data($rows, false, $e->fields, lang('FxSettings.errorInvalid'))));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('FxSettings.saved')]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/fx')->setBody('');
        }

        return redirect()->to('/settings/fx');
    }

    /**
     * @param array<string, array<string, mixed>>|null $draft  odoslane hodnoty pri 422, inak null (hodnoty z DB)
     * @param list<string>                             $invalid
     *
     * @return array<string, mixed>
     */
    private function data(?array $draft, bool $stale, array $invalid, ?string $error): array
    {
        $rows = [];

        foreach ((new FxSettingsService())->all() as $s) {
            $row = ['source' => $s['source'], 'date_basis' => $s['date_basis'], 'day_rule' => $s['day_rule'], 'revision' => $s['revision']];

            if ($draft !== null) {
                foreach (['source', 'date_basis', 'day_rule'] as $f) {
                    $row[$f] = (string) ($draft[$s['country']][$f] ?? '');
                }
            }

            $rows[$s['country']] = $row;
        }

        return ['rows' => $rows, 'stale' => $stale, 'invalid' => $invalid, 'error' => $error];
    }

    private function field(string $country, string $name): string
    {
        $group = $this->request->getPost($country);

        if (! is_array($group)) {
            return '';
        }

        $raw = $group[$name] ?? '';

        return is_string($raw) ? $raw : '';
    }
}
