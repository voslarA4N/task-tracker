<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Delivery\EmailTemplateRejected;
use App\Libraries\Delivery\EmailTemplateService;
use App\Libraries\Delivery\TemplateRenderer;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Sablony e-mailov (MAIL-04, D-14 az D-17): zoznam, uprava a nahlad. Mazanie ani pridavanie sa neponuka.
 */
final class EmailTemplates extends BaseController
{
    public function index(): string
    {
        $groups = array_fill_keys(EmailTemplateService::KEYS, []);

        foreach ((new EmailTemplateService())->listAll() as $row) {
            $groups[(string) $row['key']][] = $row;
        }

        return view('settings/email_templates/index', ['groups' => $groups]);
    }

    public function edit(int $id): string
    {
        $tpl = $this->templateOr404($id);

        return view('settings/email_templates/edit', $this->formData($tpl, (string) $tpl['subject'], (string) $tpl['body'], (int) $tpl['revision'], null, null, false));
    }

    public function update(int $id): RedirectResponse|ResponseInterface
    {
        $tpl      = $this->templateOr404($id);
        $subject  = $this->postString('subject');
        $body     = $this->postString('body');
        $revision = (int) $this->postString('revision');

        try {
            $newRevision = (new EmailTemplateService())->update($id, $subject, $body, $revision);
        } catch (EmailTemplateRejected $e) {
            if ($e->reason === 'not_found') {
                throw PageNotFoundException::forPageNotFound(lang('EmailTemplates.notFound'));
            }

            $stale = $e->reason === 'stale';
            $error = match ($e->reason) {
                'stale'               => lang('EmailTemplates.errorStale'),
                'unknown_placeholder' => lang('EmailTemplates.errorUnknown', ['{{' . $e->detail . '}}']),
                'too_long'            => lang('EmailTemplates.errorTooLong'),
                'invalid'             => lang('EmailTemplates.errorInvalidSubject'),
                default               => lang('EmailTemplates.errorEmpty'),
            };
            $field = $e->reason === 'invalid' || $e->reason === 'too_long' && mb_strlen($subject) > EmailTemplateService::MAX_SUBJECT ? 'subject' : 'body';

            if ($e->reason === 'empty') {
                $field = trim($subject) === '' ? 'subject' : 'body';
            }

            // Pri konflikte formular dostane aktualnu reviziu; navrh ostane v poliach, aktualne znenie je z DB (WR-06).
            if ($stale) {
                $tpl      = (new EmailTemplateService())->find($id) ?? $tpl;
                $revision = (int) $tpl['revision'];
            }

            return $this->response->setStatusCode($stale ? 409 : 422)
                ->setBody(view('settings/email_templates/edit', $this->formData($tpl, $subject, $body, $revision, $error, $stale ? null : $field, $stale)));
        }

        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('EmailTemplates.saved', [$newRevision])]);

        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/email-templates')->setBody('');
        }

        return redirect()->to('/settings/email-templates');
    }

    /**
     * Nahlad na vzorovych hodnotach; nic sa neuklada. Rovnaky renderer ako ostre odoslanie.
     */
    public function preview(int $id): string
    {
        $tpl     = $this->templateOr404($id);
        $subject = $this->postString('subject');
        $body    = $this->postString('body');
        $unknown = TemplateRenderer::unknownPlaceholders($subject . "\n" . $body);

        if ($unknown !== []) {
            return view('settings/email_templates/_preview', ['error' => lang('EmailTemplates.errorUnknown', ['{{' . $unknown[0] . '}}']), 'subject' => '', 'text' => '']);
        }

        $rendered = (new TemplateRenderer())->render($subject, $body, TemplateRenderer::sampleValues((string) $tpl['language']));

        return view('settings/email_templates/_preview', ['error' => null, 'subject' => $rendered['subject'], 'text' => $rendered['text']]);
    }

    /**
     * @param array<string, mixed> $tpl
     *
     * @return array<string, mixed>
     */
    private function formData(array $tpl, string $subject, string $body, int $revision, ?string $error, ?string $errorField, bool $stale): array
    {
        return ['tpl' => $tpl, 'subject' => $subject, 'body' => $body, 'revision' => $revision, 'error' => $error, 'errorField' => $errorField, 'stale' => $stale];
    }

    private function postString(string $name): string
    {
        $raw = $this->request->getPost($name);

        return is_string($raw) ? $raw : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function templateOr404(int $id): array
    {
        $tpl = (new EmailTemplateService())->find($id);

        if ($tpl === null) {
            throw PageNotFoundException::forPageNotFound(lang('EmailTemplates.notFound'));
        }

        return $tpl;
    }
}
