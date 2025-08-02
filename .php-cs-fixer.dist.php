<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
    ])
    ->append([
        __FILE__, // Include this file itself
        __DIR__ . '/bin/term-to-svg',
        __DIR__ . '/build.php',
    ]);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
;
