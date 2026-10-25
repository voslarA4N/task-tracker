<?php
use App\Libraries\Master\BillingMode;
use App\Libraries\Master\Currency;
use App\Libraries\Master\DocLanguage;
use App\Libraries\Master\IdentifierType;

/**
 * @var int|null              $id
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, string> $countries
 */
$errors = $errors ?? [];
$v      = static fn (string $k): string => (string) ($values[$k] ?? '');

$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="f-' . esc($name, 'attr') . '-error"' : '';
$feedback = static fn (string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback d-block" id="f-' . esc($name, 'attr') . '-error">' . esc($errors[$name]) . '</div>'
    : '';
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' is-invalid' : '';

$action = $id === null ? '/settings/clients' : '/settings/clients/' . $id;
$title  = lang($id === null ? 'Clients.newTitle' : 'Clients.editTitle');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/clients"><?= esc(lang('Clients.listTitle')) ?></a></p>

<section class="app-card" aria-labelledby="client-form-title">
    <h1 class="h4 mb-3" id="client-form-title"><?= esc($title) ?></h1>

    <?php if ($errors !== []) : ?>
        <div class="alert alert-danger" role="alert"><?= esc(lang('Clients.errorForm')) ?></div>
    <?php endif ?>

    <form method="post" action="<?= esc($action, 'attr') ?>" data-submit-once novalidate
          x-data="clientForm" data-identifier-type="<?= esc($v('identifier_type'), 'attr') ?>" data-billing-mode="<?= esc($v('billing_mode'), 'attr') ?>">
        <?= csrf_field() ?>

        <h2 class="h5 mb-3"><?= esc(lang('Clients.groupBasic')) ?></h2>
        <div class="mb-3">
            <label class="form-label" for="f-name"><?= esc(lang('Clients.name')) ?></label>
            <input class="form-control<?= $invalid('name') ?>" type="text" id="f-name" name="name" value="<?= esc($v('name'), 'attr') ?>" autocomplete="organization"<?= $describe('name') ?>>
            <?= $feedback('name') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-address_line1"><?= esc(lang('Clients.addressLine1')) ?></label>
            <input class="form-control<?= $invalid('address_line1') ?>" type="text" id="f-address_line1" name="address_line1" value="<?= esc($v('address_line1'), 'attr') ?>" autocomplete="street-address"<?= $describe('address_line1') ?>>
            <?= $feedback('address_line1') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-address_line2"><?= esc(lang('Clients.addressLine2')) ?></label>
            <input class="form-control<?= $invalid('address_line2') ?>" type="text" id="f-address_line2" name="address_line2" value="<?= esc($v('address_line2'), 'attr') ?>" autocomplete="off"<?= $describe('address_line2') ?>>
            <?= $feedback('address_line2') ?>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label" for="f-postal_code"><?= esc(lang('Clients.postalCode')) ?></label>
                <input class="form-control<?= $invalid('postal_code') ?>" type="text" id="f-postal_code" name="postal_code" value="<?= esc($v('postal_code'), 'attr') ?>" autocomplete="postal-code"<?= $describe('postal_code') ?>>
                <?= $feedback('postal_code') ?>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="f-city"><?= esc(lang('Clients.city')) ?></label>
                <input class="form-control<?= $invalid('city') ?>" type="text" id="f-city" name="city" value="<?= esc($v('city'), 'attr') ?>" autocomplete="address-level2"<?= $describe('city') ?>>
                <?= $feedback('city') ?>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-country"><?= esc(lang('Clients.country')) ?></label>
            <select class="form-select<?= $invalid('country') ?>" id="f-country" name="country"<?= $describe('country') ?>>
                <?php foreach ($countries as $code => $label) : ?>
                    <option value="<?= esc($code, 'attr') ?>"<?= $v('country') === $code ? ' selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach ?>
            </select>
            <?= $feedback('country') ?>
        </div>

        <h2 class="h5 mt-4 mb-3"><?= esc(lang('Clients.groupIdentity')) ?></h2>
        <fieldset class="mb-3">
            <legend class="form-label small fw-semibold"><?= esc(lang('Clients.identifierType')) ?></legend>
            <?php foreach (IdentifierType::cases() as $type) : ?>
                <div class="form-check master-check">
                    <input class="form-check-input" type="radio" name="identifier_type" id="f-identifier_type-<?= esc($type->value, 'attr') ?>"
                           value="<?= esc($type->value, 'attr') ?>"<?= $v('identifier_type') === $type->value ? ' checked' : '' ?> x-on:change="setIdentifier">
                    <label class="form-check-label" for="f-identifier_type-<?= esc($type->value, 'attr') ?>"><?= esc($type->label()) ?></label>
                </div>
            <?php endforeach ?>
            <?= $feedback('identifier_type') ?>
        </fieldset>
        <div class="mb-3">
            <label class="form-label" for="f-identifier_value"><?= esc(lang('Clients.identifierValue')) ?></label>
            <input class="form-control<?= $invalid('identifier_value') ?>" type="text" id="f-identifier_value" name="identifier_value" value="<?= esc($v('identifier_value'), 'attr') ?>" autocomplete="off" x-bind:disabled="identifierDisabled"<?= $describe('identifier_value') ?>>
            <?= $feedback('identifier_value') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-tax_id"><?= esc(lang('Clients.taxId')) ?></label>
            <input class="form-control<?= $invalid('tax_id') ?>" type="text" id="f-tax_id" name="tax_id" value="<?= esc($v('tax_id'), 'attr') ?>" autocomplete="off"<?= $describe('tax_id') ?>>
            <?= $feedback('tax_id') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-vat_id"><?= esc(lang('Clients.vatId')) ?></label>
            <input class="form-control<?= $invalid('vat_id') ?>" type="text" id="f-vat_id" name="vat_id" value="<?= esc($v('vat_id'), 'attr') ?>" autocomplete="off" x-bind:required="vatRequired" aria-describedby="f-vat_id-hint<?= isset($errors['vat_id']) ? ' f-vat_id-error' : '' ?>">
            <div class="form-text" id="f-vat_id-hint"><?= esc(lang('Clients.vatHintEuB2b')) ?></div>
            <?= $feedback('vat_id') ?>
        </div>
        <fieldset class="mb-3">
            <legend class="form-label small fw-semibold"><?= esc(lang('Clients.billingMode')) ?></legend>
            <?php foreach (BillingMode::cases() as $mode) : ?>
                <div class="form-check master-check">
                    <input class="form-check-input" type="radio" name="billing_mode" id="f-billing_mode-<?= esc($mode->value, 'attr') ?>"
                           value="<?= esc($mode->value, 'attr') ?>"<?= $v('billing_mode') === $mode->value ? ' checked' : '' ?> x-on:change="setBilling">
                    <label class="form-check-label" for="f-billing_mode-<?= esc($mode->value, 'attr') ?>"><?= esc($mode->label()) ?></label>
                </div>
            <?php endforeach ?>
            <?= $feedback('billing_mode') ?>
        </fieldset>

        <h2 class="h5 mt-4 mb-3"><?= esc(lang('Clients.groupBilling')) ?></h2>
        <div class="row g-3 mb-3">
            <div class="col-8">
                <label class="form-label" for="f-hourly_rate"><?= esc(lang('Clients.hourlyRate')) ?></label>
                <input class="form-control<?= $invalid('hourly_rate') ?>" type="text" inputmode="decimal" id="f-hourly_rate" name="hourly_rate" value="<?= esc($v('hourly_rate'), 'attr') ?>" autocomplete="off"<?= $describe('hourly_rate') ?>>
                <?= $feedback('hourly_rate') ?>
            </div>
            <div class="col-4">
                <label class="form-label" for="f-currency"><?= esc(lang('Clients.currency')) ?></label>
                <select class="form-select<?= $invalid('currency') ?>" id="f-currency" name="currency"<?= $describe('currency') ?>>
                    <?php foreach (Currency::cases() as $currency) : ?>
                        <option value="<?= esc($currency->value, 'attr') ?>"<?= $v('currency') === $currency->value ? ' selected' : '' ?>><?= esc($currency->value) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $feedback('currency') ?>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-doc_language"><?= esc(lang('Clients.docLanguage')) ?></label>
            <select class="form-select<?= $invalid('doc_language') ?>" id="f-doc_language" name="doc_language"<?= $describe('doc_language') ?>>
                <option value=""<?= $v('doc_language') === '' ? ' selected' : '' ?>><?= esc(lang('Clients.docLanguageDefault')) ?></option>
                <?php foreach (DocLanguage::cases() as $language) : ?>
                    <option value="<?= esc($language->value, 'attr') ?>"<?= $v('doc_language') === $language->value ? ' selected' : '' ?>><?= esc($language->label()) ?></option>
                <?php endforeach ?>
            </select>
            <?= $feedback('doc_language') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-due_days"><?= esc(lang('Clients.dueDays')) ?></label>
            <input class="form-control<?= $invalid('due_days') ?>" type="text" inputmode="numeric" id="f-due_days" name="due_days" value="<?= esc($v('due_days'), 'attr') ?>" placeholder="<?= esc(lang('Clients.dueDaysPlaceholder'), 'attr') ?>" autocomplete="off"<?= $describe('due_days') ?>>
            <?= $feedback('due_days') ?>
        </div>
        <div class="form-check master-check mb-3">
            <input class="form-check-input" type="checkbox" id="f-counts_toward_limit" name="counts_toward_limit" value="1"<?= $v('counts_toward_limit') === '1' ? ' checked' : '' ?>>
            <label class="form-check-label" for="f-counts_toward_limit"><?= esc(lang('Clients.countsTowardLimit')) ?></label>
        </div>

        <h2 class="h5 mt-4 mb-3"><?= esc(lang('Clients.groupPortal')) ?></h2>
        <div class="mb-3">
            <label class="form-label" for="f-portal_name"><?= esc(lang('Clients.portalName')) ?></label>
            <input class="form-control<?= $invalid('portal_name') ?>" type="text" id="f-portal_name" name="portal_name" value="<?= esc($v('portal_name'), 'attr') ?>" autocomplete="off"<?= $describe('portal_name') ?>>
            <?= $feedback('portal_name') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-portal_url_template"><?= esc(lang('Clients.portalUrlTemplate')) ?></label>
            <input class="form-control<?= $invalid('portal_url_template') ?>" type="url" id="f-portal_url_template" name="portal_url_template" value="<?= esc($v('portal_url_template'), 'attr') ?>" autocomplete="off"
                   hx-get="/settings/clients/portal-preview" hx-trigger="keyup changed delay:300ms, change" hx-target="#portal-preview" hx-swap="outerHTML" hx-include="this"
                   aria-describedby="f-portal_url_template-hint<?= isset($errors['portal_url_template']) ? ' f-portal_url_template-error' : '' ?>">
            <div class="form-text" id="f-portal_url_template-hint"><?= esc(lang('Clients.portalHint')) ?></div>
            <?= $feedback('portal_url_template') ?>
            <?php if (! isset($errors['portal_url_template'])) : ?>
                <?= $this->include('settings/clients/_portal_preview') ?>
            <?php else : ?>
                <div id="portal-preview"></div>
            <?php endif ?>
        </div>

        <h2 class="h5 mt-4 mb-3"><?= esc(lang('Clients.groupEmails')) ?></h2>
        <div class="mb-3">
            <label class="form-label" for="f-invoice_email"><?= esc(lang('Clients.invoiceEmail')) ?></label>
            <input class="form-control<?= $invalid('invoice_email') ?>" type="email" id="f-invoice_email" name="invoice_email" value="<?= esc($v('invoice_email'), 'attr') ?>" autocomplete="email"<?= $describe('invoice_email') ?>>
            <?= $feedback('invoice_email') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="f-cc_emails"><?= esc(lang('Clients.ccEmails')) ?></label>
            <textarea class="form-control<?= $invalid('cc_emails') ?>" id="f-cc_emails" name="cc_emails" rows="3" autocomplete="off" aria-describedby="f-cc_emails-hint<?= isset($errors['cc_emails']) ? ' f-cc_emails-error' : '' ?>"><?= esc($v('cc_emails')) ?></textarea>
            <div class="form-text" id="f-cc_emails-hint"><?= esc(lang('Clients.ccEmailsHint')) ?></div>
            <?= $feedback('cc_emails') ?>
        </div>

        <div class="master-sticky-actions">
            <button type="submit" class="btn btn-primary btn-touch w-100">
                <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                <?= esc(lang($id === null ? 'Clients.addButton' : 'Clients.saveButton')) ?>
            </button>
            <a class="btn btn-link w-100" href="/settings/clients"><?= esc(lang('Clients.cancel')) ?></a>
        </div>
    </form>
</section>

<?php if ($id !== null && is_array($client ?? null)) : ?>
    <?php $isArchived = ($client['archived_at'] ?? null) !== null; ?>
    <section class="app-card mt-3" aria-labelledby="client-archive-title">
        <h2 class="h5 mb-2" id="client-archive-title"><?= esc(lang('Clients.archiveTitle')) ?></h2>
        <p class="text-body-secondary"><?= esc(lang('Clients.archiveInfo')) ?></p>
        <?php if ($isArchived) : ?>
            <form method="post" action="/settings/clients/<?= (int) $id ?>/unarchive" data-submit-once>
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-touch w-100"><?= esc(lang('Clients.unarchiveButton')) ?></button>
            </form>
        <?php else : ?>
            <button type="button" class="btn btn-outline-danger btn-touch w-100" data-bs-toggle="modal" data-bs-target="#archive-client"><?= esc(lang('Clients.archiveButton')) ?></button>

            <div class="modal fade" id="archive-client" tabindex="-1" aria-labelledby="archive-client-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title h5" id="archive-client-title"><?= esc(lang('Clients.archiveButton')) ?></h3>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0"><?= esc(lang('Clients.archiveConfirm', [(string) $client['name']])) ?></p>
                        </div>
                        <div class="modal-footer flex-column flex-sm-row">
                            <form method="post" action="/settings/clients/<?= (int) $id ?>/archive" class="w-100 w-sm-auto m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger w-100"><?= esc(lang('Clients.archiveButton')) ?></button>
                            </form>
                            <button type="button" class="btn btn-outline-secondary w-100 w-sm-auto" data-bs-dismiss="modal"><?= esc(lang('Clients.cancel')) ?></button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif ?>
    </section>
<?php endif ?>
<?= $this->endSection() ?>
