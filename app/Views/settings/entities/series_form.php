<?php
/**
 * @var array<string, mixed>   $entity
 * @var string                 $kind
 * @var array<string, string>  $values
 * @var array<string, string>  $errors
 * @var bool                   $locked
 * @var string                 $title
 */
$eid    = (int) $entity['id'];
$base   = '/settings/entities/' . $eid . '/series/' . $kind;
$format = esc($values['format'], 'attr');
$init   = esc($values['initial_number'], 'attr');
$tokens = [
    ['RRRR', 'Entities.seriesTokenYear4'],
    ['RR', 'Entities.seriesTokenYear2'],
    ['MM', 'Entities.seriesTokenMonth'],
    ['###', 'Entities.seriesTokenSeq'],
    ['A-Z 0-9 / _ . -', 'Entities.seriesTokenText'],
];
$examples = [['RRRRMM###', '202610001'], ['DB-RRRRMM###', 'DB-202610001']];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/entities/<?= $eid ?>"><?= esc(lang('Entities.backToDetail')) ?></a></p>

<section class="app-card" aria-labelledby="series-form-title">
    <h1 class="h4 mb-3" id="series-form-title"><?= esc($title) ?></h1>

    <?php if ($errors !== []) : ?>
        <div class="alert alert-danger" role="alert"><?= esc(lang('Entities.errorForm')) ?></div>
    <?php endif ?>
    <?php if ($locked) : ?>
        <div class="alert alert-info" role="status"><?= esc(lang('Entities.seriesLockedHint')) ?></div>
    <?php endif ?>

    <form method="post" action="<?= $base ?>" data-submit-once novalidate>
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label" for="f-format"><?= esc(lang('Entities.seriesFormat')) ?></label>
            <input class="form-control master-iban<?= isset($errors['format']) ? ' is-invalid' : '' ?>" type="text" id="f-format" name="format" value="<?= $format ?>"
                   autocomplete="off" spellcheck="false" autocapitalize="characters" maxlength="40"<?= $locked ? ' readonly' : '' ?>
                   hx-get="<?= $base ?>/preview" hx-trigger="keyup changed delay:300ms, change" hx-target="#series-preview" hx-swap="outerHTML" hx-include="closest form">
            <?php if (isset($errors['format'])) : ?><div class="invalid-feedback"><?= esc($errors['format']) ?></div><?php endif ?>
            <?= $this->include('settings/entities/_series_preview') ?>
        </div>

        <div class="mb-3">
            <label class="form-label" for="f-initial"><?= esc(lang('Entities.seriesInitial')) ?></label>
            <input class="form-control<?= isset($errors['initial_number']) ? ' is-invalid' : '' ?>" type="text" id="f-initial" name="initial_number" value="<?= $init ?>"
                   inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="9"
                   hx-get="<?= $base ?>/preview" hx-trigger="keyup changed delay:300ms, change" hx-target="#series-preview" hx-swap="outerHTML" hx-include="closest form">
            <?php if (isset($errors['initial_number'])) : ?><div class="invalid-feedback"><?= esc($errors['initial_number']) ?></div><?php endif ?>
        </div>

        <div class="app-card security-card mb-3">
            <h2 class="h6"><?= esc(lang('Entities.seriesTokensTitle')) ?></h2>
            <dl class="master-kv small mb-2">
                <?php foreach ($tokens as [$token, $descriptionKey]) : ?>
                    <dt class="master-iban"><?= esc($token) ?></dt>
                    <dd><?= esc(lang($descriptionKey)) ?></dd>
                <?php endforeach ?>
            </dl>
            <div class="small text-body-secondary">
                <?= esc(lang('Entities.seriesExamples')) ?>:
                <?php foreach ($examples as $i => [$example, $sample]) : ?>
                    <?= $i > 0 ? ', ' : '' ?><span class="master-iban"><?= esc($example) ?></span> &rarr; <?= esc($sample) ?>
                <?php endforeach ?>
            </div>
        </div>

        <div class="master-sticky-actions master-actions">
            <a class="btn btn-outline-secondary btn-touch" href="/settings/entities/<?= $eid ?>"><?= esc(lang('Entities.cancel')) ?></a>
            <button type="submit" class="btn btn-primary btn-touch"><?= esc(lang('Entities.seriesSave')) ?></button>
        </div>
    </form>
</section>
<?= $this->endSection() ?>
