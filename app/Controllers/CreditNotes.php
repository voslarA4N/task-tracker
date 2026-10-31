<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Invoicing\CorrectionType;
use App\Libraries\Invoicing\CreditNoteDraftService;
use App\Libraries\Invoicing\CreditNoteInput;
use App\Libraries\Invoicing\CreditNoteRejected;
use App\Libraries\Invoicing\PreviewInput;
use App\Libraries\Platform\Uuid;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Vystavenie dobropisu (INV-07, INV-10): nahlad, potvrdenie a vystavenie. Kontroler nic nepocita ani nezapisuje,
 * vsetko robi CreditNoteDraftService (iba citanie) a CreditNoteIssuer (jediny zapisovatel).
 */
final class CreditNotes extends BaseController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function new(int $id): ResponseInterface
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        $date = $request->getGet('issue_date');

        return $this->form($id, ['issue_date' => is_string($date) ? trim($date) : '', 'reason' => '', 'correction_type' => '', 'request_key' => Uuid::v4(), 'confirm_backdate' => ''], []);
    }

    /**
     * Prekreslenie nahladu po zmene typu, dovodu alebo datumu (htmx, POST aby dovod nebol v URL ani v logoch).
     */
    public function preview(int $id): ResponseInterface
    {
        $values = $this->values();
        $loaded = $this->load($id, $values);

        if ($loaded instanceof ResponseInterface) {
            return $loaded;
        }

        $all = CreditNoteInput::validate($values, (string) $loaded['original']['issue_date'], $loaded['has_fx']);

        // Nevyplnene povinne polia sa pri priebeznom prekreslovani nehlasia, iba blokuju tlacidlo Pokracovat.
        $shown = $all;

        if ($values['correction_type'] === '') {
            unset($shown['correction_type']);
        }

        if ($values['reason'] === '') {
            unset($shown['reason']);
        }

        return $this->render($id, $loaded, $values, $shown, $all !== [] ? 422 : 200, []);
    }

    /**
     * Potvrdzovaci sheet (htmx) alebo samostatna stranka (bez JS).
     */
    public function confirm(int $id): ResponseInterface
    {
        $values = $this->values();
        $loaded = $this->load($id, $values);

        if ($loaded instanceof ResponseInterface) {
            return $loaded;
        }

        $errors = CreditNoteInput::validate($values, (string) $loaded['original']['issue_date'], $loaded['has_fx']);

        if ($errors === [] && $loaded['backdate'] !== null && $values['confirm_backdate'] !== '1') {
            $errors['confirm_backdate'] = lang('CreditNotes.errorBackdate');
        }

        if ($errors !== []) {
            return $this->render($id, $loaded, $values, $errors, 422, []);
        }

        if ($loaded['token'] === null || $loaded['sum_check'] !== '0.00') {
            return $this->render($id, $loaded, $values, [], 409, [['type' => 'danger', 'message' => lang('CreditNotes.errorSum')]]);
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $values['preview_token']) === 1 && ! hash_equals($loaded['token'], $values['preview_token'])) {
            return $this->render($id, $loaded, $values, [], 409, [['type' => 'warning', 'message' => lang('CreditNotes.errorStale')]]);
        }

        if (preg_match(self::UUID, $values['request_key']) !== 1) {
            $values['request_key'] = Uuid::v4();
        }

        $data = $this->viewData($id, $loaded, $values, [], []);
        $body = is_htmx_fragment_request() ? view('credit_notes/_confirm_sheet', $data) : view('credit_notes/confirm_page', $data);

        return $this->response->setStatusCode(200)->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    /**
     * Vystavenie dobropisu. Sumy, riadky ani kurz sa z formulara neprijimaju (D-20).
     */
    public function issue(int $id): ResponseInterface
    {
        $values = $this->values();
        $drafts = new CreditNoteDraftService();

        $replay = $drafts->replayFor($id, $values['request_key']);

        if ($replay !== null) {
            return $this->issuedRedirect($replay['id'], $replay['number'], null, null);
        }

        $loaded = $this->load($id, $values);

        if ($loaded instanceof ResponseInterface) {
            return $loaded;
        }

        $errors = CreditNoteInput::validate($values, (string) $loaded['original']['issue_date'], $loaded['has_fx']);

        if ($errors !== []) {
            return $this->render($id, $loaded, $values, $errors, 422, []);
        }

        if (CreditNoteInput::validateKeys($values) !== []) {
            $values['request_key'] = preg_match(self::UUID, $values['request_key']) === 1 ? $values['request_key'] : Uuid::v4();

            return $this->render($id, $loaded, $values, [], 409, [['type' => 'warning', 'message' => lang('CreditNotes.errorStale')]]);
        }

        try {
            $issued = service('creditNoteIssuer')->issue(CreditNoteInput::toCommand($values, $id, auth()->id() === null ? null : (int) auth()->id()));
        } catch (CreditNoteRejected $e) {
            return $this->rejected($e, $id, $loaded, $values);
        } catch (Throwable $e) {
            log_message('error', 'Credit note issue failed: {class}: {message}', ['class' => $e::class, 'message' => $e->getMessage()]);

            return $this->render($id, $loaded, $values, [], 500, [['type' => 'danger', 'message' => lang('CreditNotes.errorFailed')]]);
        }

        return $this->issuedRedirect($issued->id, $issued->number, $issued->releasedParts, $drafts->releaseLink($issued->originalId));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(int $id, array $values, array $errors): ResponseInterface
    {
        $loaded = $this->load($id, $values);

        if ($loaded instanceof ResponseInterface) {
            return $loaded;
        }

        return $this->render($id, $loaded, $values, $errors, 200, []);
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, mixed>|ResponseInterface nahlad s klucom has_fx, alebo odpoved pre odmietnuty doklad
     */
    private function load(int $id, array $values): array|ResponseInterface
    {
        try {
            $draft = (new CreditNoteDraftService())->draft(
                $id,
                $values['issue_date'] === '' ? null : $values['issue_date'],
                $values['reason'],
                CorrectionType::tryFrom($values['correction_type']),
            );
        } catch (CreditNoteRejected $e) {
            return $this->blocked($e, $id, $values);
        }

        return $draft;
    }

    /**
     * @param array<string, string> $values
     */
    private function blocked(CreditNoteRejected $e, int $id, array $values): ResponseInterface
    {
        if ($e->reason === 'already_credited' && $e->creditNoteId !== null) {
            $alert = [
                'type'    => 'warning',
                'message' => lang('CreditNotes.errorExists', [(string) $e->creditNoteNumber]),
                'link'    => ['href' => '/invoices/' . $e->creditNoteId, 'label' => lang('CreditNotes.showCreditNote', [(string) $e->creditNoteNumber])],
            ];
            $data = ['id' => $id, 'draft' => null, 'values' => $values, 'errors' => [], 'alerts' => [$alert]];

            return $this->response->setStatusCode(409)->setHeader('Vary', 'HX-Request')
                ->setBody(htmx_view('credit_notes/_preview', $data, 'credit_notes/new_page'));
        }

        throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));
    }

    /**
     * @param array<string, mixed>                                                                  $loaded
     * @param array<string, string>                                                                 $values
     * @param array<string, string>                                                                 $errors
     * @param list<array{type: string, message: string, link?: array{href: string, label: string}}> $alerts
     */
    private function render(int $id, array $loaded, array $values, array $errors, int $status, array $alerts): ResponseInterface
    {
        $data = $this->viewData($id, $loaded, $values, $errors, $alerts);

        // Odpoved s chybou z odoslania patri do oblasti nahladu, nie do sheetu.
        $response = $this->response->setStatusCode($status)->setHeader('Vary', 'HX-Request');

        if (is_htmx_fragment_request()) {
            $response->setHeader('HX-Retarget', '#cn-preview')->setHeader('HX-Reswap', 'innerHTML');
        }

        return $response->setBody(htmx_view('credit_notes/_preview', $data, 'credit_notes/new_page'));
    }

    /**
     * @param array<string, mixed>                                                                  $loaded
     * @param array<string, string>                                                                 $values
     * @param array<string, string>                                                                 $errors
     * @param list<array{type: string, message: string, link?: array{href: string, label: string}}> $alerts
     *
     * @return array<string, mixed>
     */
    private function viewData(int $id, array $loaded, array $values, array $errors, array $alerts): array
    {
        if (preg_match(self::UUID, $values['request_key']) !== 1) {
            $values['request_key'] = Uuid::v4();
        }

        if (! isset($errors['issue_date'])) {
            $values['issue_date'] = (string) $loaded['issue_date'];
        }

        return ['id' => $id, 'draft' => $loaded, 'values' => $values, 'errors' => $errors, 'alerts' => $alerts, 'locale' => service('request')->getLocale()];
    }

    /**
     * @param array<string, mixed>  $loaded
     * @param array<string, string> $values
     */
    private function rejected(CreditNoteRejected $e, int $id, array $loaded, array $values): ResponseInterface
    {
        $warn = static fn (string $key): array => [['type' => 'warning', 'message' => lang($key)]];

        switch ($e->reason) {
            case 'already_issued':
                if ($e->creditNoteId !== null) {
                    return $this->issuedRedirect($e->creditNoteId, $e->creditNoteNumber, null, null);
                }

                return $this->render($id, $loaded, $values, [], 409, $warn('CreditNotes.errorStale'));

            case 'already_credited':
                return $this->blocked($e, $id, $values);

            case 'not_found':
            case 'not_creditable':
                throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));

            case 'preview_stale':
            case 'parts_mismatch':
                return $this->render($id, $loaded, $values, [], 409, $warn('CreditNotes.errorStale'));

            case 'sum_mismatch':
                return $this->render($id, $loaded, $values, [], 409, [['type' => 'danger', 'message' => lang('CreditNotes.errorSum')]]);

            case 'backdate_unconfirmed':
                return $this->render($id, $loaded, $values, ['confirm_backdate' => lang('CreditNotes.errorBackdate')], 422, []);

            case 'date_before_original':
                return $this->render($id, $loaded, $values, ['issue_date' => lang('CreditNotes.errorDateBeforeOriginal', [Invoicing::dateLabel((string) $loaded['original']['issue_date'])])], 422, []);

            case 'issue_future':
                return $this->render($id, $loaded, $values, ['issue_date' => lang('CreditNotes.errorIssueFuture', [PreviewInput::ISSUE_MAX_AHEAD_DAYS])], 422, []);

            case 'number_collision':
                return $this->render($id, $loaded, $values, [], 422, [['type' => 'danger', 'message' => lang('CreditNotes.errorNumber')]]);

            default: // invalid_series_format, sequence_overflow
                return $this->render($id, $loaded, $values, [], 422, [['type' => 'danger', 'message' => lang('CreditNotes.errorSeries')]]);
        }
    }

    private function issuedRedirect(int $creditNoteId, ?string $number, ?int $released, ?string $releasedLink): ResponseInterface
    {
        if ($number !== null) {
            $message = lang('CreditNotes.flashIssued', [$number]);
            $notice  = ['type' => 'success', 'message' => $released === null ? $message : $message . ' ' . lang('CreditNotes.flashReleased', [$released])];

            if ($released !== null && $releasedLink !== null) {
                $notice['link'] = ['href' => $releasedLink, 'label' => lang('CreditNotes.linkReleased')];
            }

            session()->setFlashdata('app_notice', $notice);
        }

        $url = '/invoices/' . $creditNoteId;

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', $url)->setBody('');
        }

        return redirect()->to($url);
    }

    /**
     * @return array{issue_date: string, reason: string, correction_type: string, preview_token: string, request_key: string, confirm_backdate: string}
     */
    private function values(): array
    {
        $request = $this->request;
        assert($request instanceof IncomingRequest);

        return CreditNoteInput::fromPost($request);
    }
}
