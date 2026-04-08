<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:page_status/Resources/Private/Language/locallang_be.xlf:tx_pagestatus_domain_model_pagestatus',
        'label' => 'page_url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'page_url,http_status_code,error_message',
        'iconfile' => 'EXT:page_status/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'page_url,is_online,http_status_code,last_check,screenshot_path,error_message,--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,hidden'],
    ],
    'palettes' => [
        '1' => ['showitem' => ''],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'Hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
                'items' => [
                    [
                        'label' => 'Hide',
                    ],
                ],
            ],
        ],
        'page_id' => [
            'exclude' => true,
            'label' => 'Page ID',
            'config' => [
                'type' => 'number',
            ],
        ],
        'page_url' => [
            'exclude' => true,
            'label' => 'Page URL',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
            ],
        ],
        'is_online' => [
            'exclude' => true,
            'label' => 'Is Online',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
                'items' => [
                    [
                        'label' => 'Online',
                    ],
                ],
            ],
        ],
        'http_status_code' => [
            'exclude' => true,
            'label' => 'HTTP Status Code',
            'config' => [
                'type' => 'number',
            ],
        ],
        'last_check' => [
            'exclude' => true,
            'label' => 'Last Check',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
            ],
        ],
        'screenshot_path' => [
            'exclude' => true,
            'label' => 'Screenshot Path',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 512,
            ],
        ],
        'error_message' => [
            'exclude' => true,
            'label' => 'Error Message',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
            ],
        ],
    ],
];
