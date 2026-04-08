<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Page Status Monitor',
    'description' => 'Monitor page availability and take screenshots of frontend pages. Check HTTP status codes and display results in the TYPO3 backend.',
    'category' => 'module',
    'author' => 'Exdeliver',
    'author_email' => '',
    'author_company' => '',
    'version' => '1.0.0',
    'state' => 'stable',
    'clearCacheOnLoad' => 1,
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.0.0',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Exdeliver\\PageStatus\\' => 'Classes/',
        ],
    ],
];
