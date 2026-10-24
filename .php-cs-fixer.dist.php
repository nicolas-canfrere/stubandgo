<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@autoPHPMigration' => true,
        '@autoPHPMigration:risky' => true,
        '@autoPHPUnitMigration:risky' => true,
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'concat_space' => ['spacing' => 'one'],
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'no_superfluous_elseif' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'allow_hidden_params' => true, 'remove_inheritdoc' => true],
        'ordered_class_elements' => true,
        'php_unit_method_casing' => false,
        'phpdoc_align' => false,
        'phpdoc_to_comment' => false,
        'phpdoc_to_property_type' => true,
        'single_line_throw' => false,
    ])
    ->setFinder($finder)
;
