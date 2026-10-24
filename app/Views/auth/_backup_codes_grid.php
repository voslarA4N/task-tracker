<ol class="code-grid">
    <?php foreach ($codes as $code) : ?>
        <li><code><?= esc($code) ?></code></li>
    <?php endforeach ?>
</ol>
<p>
    <a class="btn btn-outline-secondary" href="<?= esc($downloadUrl, 'attr') ?>" download="work-tracker-zalozne-kody.txt"><?= esc(lang('TwoFactor.download')) ?></a>
</p>
