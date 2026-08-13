<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// Rule set trimmed down from the shopware/shopware root configuration.
return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,

        'blank_line_after_opening_tag' => false,
        'class_attributes_separation' => ['elements' => ['property' => 'one', 'method' => 'one']],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'linebreak_after_opening_tag' => false,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'native_function_invocation' => ['scope' => 'namespaced', 'strict' => false],
        'no_superfluous_phpdoc_tags' => ['allow_unused_params' => true, 'allow_mixed' => true],
        'nullable_type_declaration_for_default_null_value' => true,
        'operator_linebreak' => ['only_booleans' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_annotation_without_dot' => false,
        'phpdoc_order' => true,
        'phpdoc_summary' => false,
        'single_line_throw' => false,
        'single_quote' => ['strings_containing_single_quote_chars' => true],
        'strict_comparison' => true,
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['after_heredoc' => true, 'elements' => ['array_destructuring', 'arrays', 'match']],
        'void_return' => true,
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
            'less_and_greater' => false,
        ],
    ])
    ->setFinder(
        (new Finder())->in([__DIR__ . '/src', __DIR__ . '/tests'])
    );
