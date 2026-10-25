<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Master\Country;
use App\Libraries\Master\EntityInput;
use App\Libraries\Master\EntityService;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\VersionNotFound;
use App\Libraries\Master\VersionRejected;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use IntlDateFormatter;

final class EntityVersions extends BaseController
{
    public function newForm(int $id): string
    {
        $entity = $this->entityOr404($id);
        $today  = service('clock')->today();
        $res    = new EntityVersionResolver();
        $from   = $this->request->getGet('from');

        if (is_string($from) && $from !== '') {
            $row = ctype_digit($from) ? $res->find($id, (int) $from) : null;

            if ($row === null) {
                throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
            }
        } else {
            try {
                $row = $res->current($id, $today);
            } catch (VersionNotFound) {
                throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
            }
        }

        $values = ['valid_from' => $today];

        foreach (EntityInput::VERSION_FIELDS as $field) {
            $values[$field] = $row[$field] === null ? '' : (string) $row[$field];
        }

        return view('settings/entities/version_new', $this->formData($entity, $values, []));
    }

    public function notice(int $id): RedirectResponse|ResponseInterface
    {
        $this->entityOr404($id);

        if (! is_htmx_fragment_request()) {
            // Celá stránka formulára potrebuje kompletné dáta, notice je len fragment (WR-08).
            return redirect()->to('/settings/entities/' . $id . '/versions/new');
        }

        $today = service('clock')->today();
        $raw   = $this->request->getGet('valid_from');
        $date  = is_string($raw) ? EntityInput::parseDate($raw) : null;
        $later = null;

        if ($date !== null) {
            $next  = (new EntityVersionResolver())->nextAfter($id, $date);
            $later = $next === null ? null : $this->formatDate((string) $next['valid_from']);
        }

        $data = [
            'future' => $date !== null && $date > $today ? $this->formatDate($date) : null,
            'later'  => $later,
        ];

        return $this->response
            ->setHeader('Vary', 'HX-Request')
            ->setBody(view('settings/entities/_version_notice', $data));
    }

    public function create(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $entity  = $this->entityOr404($id);
        $country = Country::from((string) $entity['country']);

        if (EntityInput::hasImmutableKeys($request)) {
            return $this->response
                ->setStatusCode(422)
                ->setBody(view('settings/entities/version_new', $this->formData($entity, $this->currentValues($request), ['_form' => lang('Entities.errorImmutable')])));
        }

        $raw     = $request->getPost('valid_from');
        $rawDate = is_string($raw) ? trim($raw) : '';

        $version = EntityInput::normalizeVersion(EntityInput::versionFromPost($request));
        $errors  = EntityInput::validateVersion($version, $country);
        $date    = EntityInput::parseDate($rawDate);
        $service = new EntityService();

        if ($date === null) {
            $errors['valid_from'] = lang('Entities.errorDate');
        }

        if ($errors === [] && $date !== null) {
            try {
                $service->newVersion($id, $date, $version);

                session()->setFlashdata('app_notice', [
                    'type'    => 'success',
                    'message' => lang('Entities.versionSaved', [$this->formatDate($date)]),
                ]);

                return redirect()->to('/settings/entities/' . $id);
            } catch (VersionRejected $e) {
                $errors['valid_from'] = match ($e->reason) {
                    'duplicate_date' => lang('Entities.errorDuplicateDate'),
                    'no_change'      => lang('Entities.errorNoChange'),
                    default          => lang('Entities.errorDateRange', [
                        $this->formatDate((string) $entity['active_from']),
                        $this->formatDate(service('clock')->parseDate(service('clock')->today())?->modify('+366 days')->format('Y-m-d') ?? ''),
                    ]),
                };
            }
        }

        $values = ['valid_from' => $rawDate];

        foreach ($version as $key => $value) {
            $values[$key] = $value ?? '';
        }

        return $this->response
            ->setStatusCode(422)
            ->setBody(view('settings/entities/version_new', $this->formData($entity, $values, $errors)));
    }

    /**
     * @return array<string, string>
     */
    private function currentValues(IncomingRequest $request): array
    {
        $raw    = $request->getPost('valid_from');
        $values = ['valid_from' => is_string($raw) ? trim($raw) : ''];

        foreach (EntityInput::normalizeVersion(EntityInput::versionFromPost($request)) as $key => $value) {
            $values[$key] = $value ?? '';
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function entityOr404(int $id): array
    {
        $entity = (new EntityService())->find($id);

        if ($entity === null) {
            throw PageNotFoundException::forPageNotFound(lang('Entities.notFound'));
        }

        return $entity;
    }

    /**
     * @param array<string, mixed>  $entity
     * @param array<string, string> $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function formData(array $entity, array $values, array $errors): array
    {
        $max = service('clock')->parseDate(service('clock')->today())?->modify('+366 days')->format('Y-m-d') ?? '';

        return [
            'title'       => lang('Entities.versionNewTitle'),
            'entity'      => $entity,
            'values'      => $values,
            'errors'      => $errors,
            'country'     => Country::from((string) $entity['country']),
            'minDate'     => (string) $entity['active_from'],
            'maxDate'     => $max,
            'future'      => null,
            'later'       => null,
            'versionMode' => true,
        ];
    }

    private function formatDate(string $iso): string
    {
        $date = service('clock')->parseDate($iso);

        if ($date === null) {
            return $iso;
        }

        $formatter = new IntlDateFormatter(
            service('request')->getLocale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::GREGORIAN,
            'd. M. yyyy',
        );
        $out = $formatter->format($date->getTimestamp());

        return $out === false ? $iso : $out;
    }
}
