<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\SeriesFormat;
use App\Libraries\Master\SeriesRejected;
use App\Libraries\Master\SeriesService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

final class Series extends BaseController
{
    public function edit(int $entityId, string $kind): string
    {
        $entity = $this->entityOr404($entityId);
        $series = $this->seriesOr404($entityId, $kind);
        $values = ['format' => (string) $series['format'], 'initial_number' => (string) $series['initial_number']];

        return view('settings/entities/series_form', $this->formData($entity, $kind, $series, $values, []));
    }

    public function preview(int $entityId, string $kind): ResponseInterface
    {
        $this->entityOr404($entityId);
        $this->seriesOr404($entityId, $kind);

        return $this->response
            ->setHeader('Vary', 'HX-Request')
            ->setBody(view('settings/entities/_series_preview', [
                'preview' => $this->previewData($this->queryString('format'), $this->queryString('initial_number')),
            ]));
    }

    public function update(int $entityId, string $kind): RedirectResponse|ResponseInterface
    {
        $entity = $this->entityOr404($entityId);
        $series = $this->seriesOr404($entityId, $kind);
        $values = ['format' => $this->postString('format'), 'initial_number' => $this->postString('initial_number')];
        $errors = [];

        try {
            (new SeriesService())->update($entityId, $kind, $values['format'], $values['initial_number']);
        } catch (InvalidSeriesFormat $e) {
            $errors['format'] = lang($e->reason === 'ambiguous_literal' ? 'Entities.errorSeriesAmbiguous' : 'Entities.errorSeriesFormat');
        } catch (SeriesRejected $e) {
            $errors += match ($e->reason) {
                'same_as_other' => ['format' => lang('Entities.errorSeriesSame')],
                'locked'        => ['format' => lang('Entities.errorSeriesLocked')],
                default         => ['initial_number' => lang('Entities.errorSeriesInitial')],
            };
        }

        if ($errors !== []) {
            return $this->response->setStatusCode(422)->setBody(view('settings/entities/series_form', $this->formData($entity, $kind, $series, $values, $errors)));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Entities.seriesSaved')]);

        return redirect()->to('/settings/entities/' . $entityId);
    }

    /**
     * @return array{error: bool, samples: list<string>, monthly: bool}
     */
    private function previewData(string $format, string $initial): array
    {
        try {
            $parsed = SeriesFormat::parse($format);
        } catch (InvalidSeriesFormat) {
            return ['error' => true, 'samples' => [], 'monthly' => false];
        }

        $start = preg_match('/^\d{1,9}$/D', $initial) === 1 ? (int) $initial : 1;
        $start = $start >= 1 && $start <= $parsed->maxSequence() ? $start : 1;
        $today = service('clock')->today();

        $samples = [$parsed->render($today, $start)];

        if ($start + 1 <= $parsed->maxSequence()) {
            $samples[] = $parsed->render($today, $start + 1);
        }

        return ['error' => false, 'samples' => $samples, 'monthly' => $parsed->resetsMonthly()];
    }

    /**
     * @param array<string, mixed>  $entity
     * @param array<string, mixed>  $series
     * @param array<string, string> $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function formData(array $entity, string $kind, array $series, array $values, array $errors): array
    {
        $label = lang($kind === 'invoice' ? 'Entities.seriesInvoices' : 'Entities.seriesCreditNotes');

        return [
            'title'   => lang('Entities.seriesEditTitle', [$label]),
            'entity'  => $entity,
            'kind'    => $kind,
            'series'  => $series,
            'values'  => $values,
            'errors'  => $errors,
            'locked'  => (new SeriesService())->isFormatLocked((int) $series['id']),
            'preview' => $this->previewData($values['format'], $values['initial_number']),
        ];
    }

    private function postString(string $name): string
    {
        $raw = $this->request->getPost($name);

        return is_string($raw) ? $raw : '';
    }

    private function queryString(string $name): string
    {
        $raw = $this->request->getGet($name);

        return is_string($raw) ? $raw : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function entityOr404(int $entityId): array
    {
        $entity = (new EntityService())->find($entityId);

        if ($entity === null) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        return $entity;
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesOr404(int $entityId, string $kind): array
    {
        $series = in_array($kind, SeriesService::KINDS, true) ? (new SeriesService())->find($entityId, $kind) : null;

        if ($series === null) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        return $series;
    }
}
