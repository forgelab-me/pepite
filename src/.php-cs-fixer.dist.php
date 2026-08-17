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
    ])
    // View templates are HTML-first and do not follow the PHP file layout.
    ->exclude(['Views'])
    ->append([__FILE__]);

$overrides = [];

$options = [
    'finder'    => $finder,
    'cacheFile' => 'writable/cache/.php-cs-fixer.cache',
];

return Factory::create(new CodeIgniter4(), $overrides, $options)->forProjects();
