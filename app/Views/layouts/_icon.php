<?php

declare(strict_types=1);

/**
 * Zdielana ikona (dlazdice Viac, navigacia). Staticke dovercovane SVG retazce, meno sa nikdy nevypisuje.
 *
 * @var string $name
 * @var int    $size  rozmer v px (default 28)
 * @var string $class CSS trieda (default more-tile-icon)
 */
$icons = [
    'reports'         => '<path d="M3 3v18h18"/><path d="M8 17v-6"/><path d="M13 17V7"/><path d="M18 17v-4"/>',
    'bundle'          => '<path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/>',
    'entities'        => '<path d="M4 21V3h10v18"/><path d="M14 9h6v12"/><path d="M8 7h2"/><path d="M8 11h2"/><path d="M8 15h2"/><path d="M2 21h20"/>',
    'clients'         => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1"/><circle cx="17" cy="9" r="2"/><path d="M17 14a4 4 0 0 1 4 4v2"/>',
    'legal-texts'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
    'fx'              => '<path d="M4 8h16"/><path d="M16 4l4 4-4 4"/><path d="M20 16H4"/><path d="M8 12l-4 4 4 4"/>',
    'documents'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h6"/><path d="M14 2l6 6v3"/><circle cx="17" cy="17" r="2"/><path d="M17 13v2"/><path d="M17 19v2"/><path d="M13.5 15l1.7 1"/><path d="M20.5 19l-1.7-1"/>',
    'limits'          => '<path d="M4 18a8 8 0 1 1 16 0"/><path d="M12 18l4-6"/><path d="M3 18h2"/><path d="M19 18h2"/>',
    'audit'           => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/>',
    'users'           => '<circle cx="10" cy="8" r="4"/><path d="M3 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1"/><path d="M19 8v6"/><path d="M16 11h6"/>',
    'email-templates' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/>',
    'overview'        => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    'tasks'           => '<path d="M3 6l2 2 3-3"/><path d="M3 13l2 2 3-3"/><path d="M11 7h10"/><path d="M11 14h10"/><path d="M11 20h10"/><path d="M4 20h2"/>',
    'invoices'        => '<path d="M5 2h14v20l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 7h6"/><path d="M9 11h6"/><path d="M9 15h4"/>',
    'more'            => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'security'        => '<path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/>',
    'logout'          => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
];

$size  = isset($size) ? (int) $size : 28;
$class = isset($class) ? (string) $class : 'more-tile-icon';

if (! isset($icons[$name])) {
    throw new InvalidArgumentException('Neznama ikona dlazdice.');
}
?>
<svg class="<?= esc($class, 'attr') ?>" width="<?= $size ?>" height="<?= $size ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $icons[$name] ?></svg>
