<?php

declare(strict_types=1);

use CodeIgniter\CodingStandard\CodeIgniter4;
use Nexus\CsConfig\Factory;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/tests',
        __DIR__ . '/scripts',
    ])
    ->exclude(['Views', '_support/PhpStan/Fixtures'])
    ->append([
        __DIR__ . '/preload.php',
        __DIR__ . '/spark',
    ]);

return Factory::create(new CodeIgniter4(), [], [
    'finder'    => $finder,
    'cacheFile' => 'build/.php-cs-fixer.cache',
])->forProjects();
