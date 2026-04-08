<?php

declare(strict_types=1);

use Exdeliver\PageStatus\Controller\PageStatusModuleController;

return [
    'page_status' => [
        'parent' => 'web',
        'position' => ['after' => '*'],
        'access' => 'user',
        'path' => '/module/page/status',
        'iconIdentifier' => 'module-page_status',
        'labels' => 'LLL:EXT:page_status/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => PageStatusModuleController::class . '::indexAction',
            ],
        ],
        'moduleData' => [
            'filter' => 'all',
            'selectedPageId' => 0,
        ],
    ],
];
