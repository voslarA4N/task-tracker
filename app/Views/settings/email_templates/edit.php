<?php
/**
 * @var array<string, mixed> $tpl
 * @var string               $subject
 * @var string               $body
 * @var int                  $revision
 * @var string|null          $error
 * @var string|null          $errorField
 * @var bool                 $stale
 */
use App\Libraries\Delivery\TemplateRenderer;

$id       = (int) $tpl['id'];
$kindKeys = ['invoice' => 'EmailTemplates.kindInvoice', 'reminder' => 'EmailTemplates.kindReminder'];
$langKeys = ['sk' => 'EmailTemplates.lang_sk', 'pl' => 'EmailTemplates.lang_pl', 'cs' => 'EmailTemplates.lang_cs', 'en' => 'EmailTemplates.lang_en'];
$varKeys  = ['number' => 'EmailTemplates.var_number', 'total' => 'EmailTemplates.var_total', 'balance' => 'EmailTemplates.var_balance', 'currency' => 'EmailTemplates.var_currency', 'issue_date' => 'EmailTemplates.var_issue_date', 'due_date' => 'EmailTemplates.var_due_date', 'billing_month' => 'EmailTemplates.var_billing_month', 'variable_symbol' => 'EmailTemplates.var_variable_symbol', 'iban' => 'EmailTemplates.var_iban', 'client_name' => 'EmailTemplates.var_client_name', 'entity_name' => 'EmailTemplates.var_entity_name', 'days_overdue' => 'EmailTemplates.var_days_overdue'];
$subjectErr = $error !== null && $errorField === 'subject';
$bodyErr    = $error !== null && $errorField === 'body';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc(lang('EmailTemplates.editTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/email-templates"><?= esc(lang('EmailTemplates.backToList')) ?></a></p>

<section class="app-card" aria-labelledby="et-title">
    <h1 class="h4 mb-3" id="et-title"><?= esc(lang('EmailTemplates.editTitle')) ?></h1>

    <dl class="master-kv small mb-3">
        <dt><?= esc(lang('EmailTemplates.kind')) ?></dt>
        <dd><?= esc(lang($kindKeys[$tpl['key']] ?? 'EmailTemplates.title')) ?></dd>
        <dt><?= esc(lang('EmailTemplates.language')) ?></dt>
        <dd><?= esc(lang($langKeys[(string) $tpl['language']] ?? 'EmailTemplates.title')) ?> (<?= esc((string) $tpl['language']) ?>)</dd>
        <dt><?= esc(lang('EmailTemplates.revision')) ?></dt>
        <dd><?= (int) $tpl['revision'] ?></dd>
        <dt><?= esc(lang('EmailTemplates.updatedAt')) ?></dt>
        <dd><?= esc(substr((string) $tpl['updated_at'], 0, 16)) ?></dd>
    </dl>

    <?php if ($stale) : ?>
        <div class="alert alert-danger" role="alert"><?= esc((string) $error) ?></div>
    <?php endif ?>

    <form method="post" action="/settings/email-templates/<?= $id ?>" data-submit-once novalidate x-data="etVars">
        <?= csrf_field() ?>
        <input type="hidden" name="revision" value="<?= (int) $revision ?>">

        <div class="mb-3">
            <label class="form-label" for="et-subject"><?= esc(lang('EmailTemplates.subject')) ?></label>
            <input class="form-control<?= $subjectErr ? ' is-invalid' : '' ?>" type="text" id="et-subject" name="subject" maxlength="200" required
                   value="<?= esc($subject, 'attr') ?>" x-on:focus="track"<?= $subjectErr ? ' aria-describedby="et-subject-error"' : '' ?>>
            <?php if ($subjectErr) : ?><div class="invalid-feedback" id="et-subject-error"><?= esc((string) $error) ?></div><?php endif ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="et-body"><?= esc(lang('EmailTemplates.body')) ?></label>
            <textarea class="form-control lt-body<?= $bodyErr ? ' is-invalid' : '' ?>" id="et-body" name="body" rows="12" maxlength="4000" required
                      x-on:focus="track" aria-describedby="et-vars-hint<?= $bodyErr ? ' et-body-error' : '' ?>"><?= esc($body) ?></textarea>
            <?php if ($bodyErr) : ?><div class="invalid-feedback" id="et-body-error"><?= esc((string) $error) ?></div><?php endif ?>
        </div>

        <div class="et-vars mb-3">
            <div class="form-label" id="et-vars-label"><?= esc(lang('EmailTemplates.varsTitle')) ?></div>
            <div class="et-vars-list" role="group" aria-labelledby="et-vars-label">
                <?php foreach (TemplateRenderer::PLACEHOLDERS as $var) : ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm et-var" data-var="{{<?= esc($var, 'attr') ?>}}" x-on:click="insert">
                        <code>{{<?= esc($var) ?>}}</code> <span class="small text-body-secondary"><?= esc(lang($varKeys[$var])) ?></span>
                    </button>
                <?php endforeach ?>
            </div>
            <div class="form-text" id="et-vars-hint"><?= esc(lang('EmailTemplates.varsHint')) ?></div>
        </div>

        <div class="et-preview mb-3">
            <button type="button" class="btn btn-outline-secondary btn-touch" hx-post="/settings/email-templates/<?= $id ?>/preview" hx-include="closest form" hx-target="#et-preview-out" hx-swap="innerHTML"><?= esc(lang('EmailTemplates.showPreview')) ?></button>
            <div id="et-preview-out" class="mt-2" aria-live="polite"></div>
        </div>

        <p class="small text-body-secondary"><?= esc(lang('EmailTemplates.laterOnly')) ?></p>

        <div class="master-sticky-actions master-actions">
            <a class="btn btn-outline-secondary btn-touch" href="/settings/email-templates"><?= esc(lang('EmailTemplates.backToList')) ?></a>
            <button type="submit" class="btn btn-primary btn-touch" id="et-save"><span class="spinner-border spinner-border-sm htmx-indicator d-none" aria-hidden="true"></span> <?= esc(lang('EmailTemplates.save')) ?></button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
