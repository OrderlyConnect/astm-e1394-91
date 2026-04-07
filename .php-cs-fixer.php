<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/examples')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                       => true,
        'declare_strict_types'         => true,
        'array_syntax'                 => ['syntax' => 'short'],
        'no_unused_imports'            => true,
        'ordered_imports'              => ['sort_algorithm' => 'alpha'],
        'single_quote'                 => true,
        'trailing_comma_in_multiline'  => true,
        'no_extra_blank_lines'         => true,
        'blank_line_after_namespace'   => true,
        'phpdoc_align'                 => ['align' => 'left'],
        'phpdoc_trim'                  => true,
        'no_superfluous_phpdoc_tags'   => false,
        'binary_operator_spaces'       => ['default' => 'align_single_space_minimal'],
    ])
    ->setFinder($finder);
