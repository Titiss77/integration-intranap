<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/htdocs')
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(false);
