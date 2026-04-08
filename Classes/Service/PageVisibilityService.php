<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Service;

use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageVisibilityService
{
    protected ConnectionPool $connectionPool;

    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }

    public function getPageVisibilityData(int $pageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $page = $queryBuilder
            ->select(
                'uid',
                'hidden',
                'starttime',
                'endtime',
                'fe_group',
                'extendToSubpages',
                'nav_hide',
                'doktype',
                'no_search',
                'deleted'
            )
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Types::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$page) {
            return [
                'isVisible' => false,
                'isHidden' => false,
                'isVisibleInMenu' => false,
                'adheresToRules' => false,
                'issues' => ['Page not found'],
                'raw' => [],
            ];
        }

        $isVisible = $this->checkVisibility($page);
        $isVisibleInMenu = !$page['nav_hide'] && $isVisible;

        $adheresToRules = $this->checkVisibilityRuleAdherence($page);
        $issues = $this->getVisibilityIssues($page);

        return [
            'isVisible' => $isVisible,
            'isHidden' => (bool) $page['hidden'],
            'starttime' => (int) $page['starttime'],
            'endtime' => (int) $page['endtime'],
            'fe_group' => $page['fe_group'] ?? '',
            'isVisibleInMenu' => $isVisibleInMenu,
            'nav_hide' => (bool) $page['nav_hide'],
            'doktype' => (int) $page['doktype'],
            'no_search' => (bool) $page['no_search'],
            'extendToSubpages' => (bool) $page['extendToSubpages'],
            'adheresToRules' => $adheresToRules,
            'issues' => $issues,
            'raw' => $page,
        ];
    }

    protected function checkVisibility(array $page): bool
    {
        if ($page['deleted']) {
            return false;
        }

        if ($page['hidden']) {
            return false;
        }

        $now = $GLOBALS['EXEC_TIME'] ?? time();

        if ($page['starttime'] > 0 && $page['starttime'] > $now) {
            return false;
        }

        if ($page['endtime'] > 0 && $page['endtime'] < $now) {
            return false;
        }

        return true;
    }

    protected function checkVisibilityRuleAdherence(array $page): bool
    {
        $issues = $this->getVisibilityIssues($page);

        return count($issues) === 0;
    }

    protected function getVisibilityIssues(array $page): array
    {
        $issues = [];

        $now = $GLOBALS['EXEC_TIME'] ?? time();

        if ($page['hidden']) {
            $issues[] = 'Page is hidden [hidden=1]';
        }

        if ($page['starttime'] > 0 && $page['starttime'] > $now) {
            $startDate = date('Y-m-d H:i:s', $page['starttime']);
            $issues[] = "Page is not yet visible [starttime={$startDate}]";
        }

        if ($page['endtime'] > 0 && $page['endtime'] < $now) {
            $endDate = date('Y-m-d H:i:s', $page['endtime']);
            $issues[] = "Page visibility has expired [endtime={$endDate}]";
        }

        if (!empty($page['fe_group'])) {
            $issues[] = "Page has frontend user group access restriction [fe_group={$page['fe_group']}]";
        }

        if ($page['nav_hide']) {
            $issues[] = 'Page is hidden in navigation [nav_hide=1]';
        }

        if ($page['no_search']) {
            $issues[] = 'Page is excluded from search [no_search=1]';
        }

        if (!in_array($page['doktype'], [1, 4, 7, 199])) {
            $doktypes = [
                1 => 'Standard',
                2 => 'Link',
                3 => 'External URL',
                4 => 'Shortcut',
                5 => 'Not in menu',
                6 => 'Backend User Section',
                7 => 'Mount Point',
                199 => 'Separator',
            ];
            $doktypeName = $doktypes[$page['doktype']] ?? 'Unknown';
            $issues[] = "Page has special doktype [doktype={$page['doktype']} ({$doktypeName})]";
        }

        if ($page['extendToSubpages']) {
            $issues[] = 'Page visibility settings extend to subpages [extendToSubpages=1]';
        }

        return $issues;
    }

    public function getPagesVisibilityData(array $pageIds): array
    {
        $visibilityData = [];

        foreach ($pageIds as $pageId) {
            $visibilityData[$pageId] = $this->getPageVisibilityData($pageId);
        }

        return $visibilityData;
    }

    public function formatForDatabase(array $visibilityData): array
    {
        return [
            'page_is_visible' => $visibilityData['isVisible'] ? 1 : 0,
            'page_hidden' => $visibilityData['isHidden'] ? 1 : 0,
            'page_starttime' => $visibilityData['starttime'],
            'page_endtime' => $visibilityData['endtime'],
            'page_fe_group' => $visibilityData['fe_group'],
            'page_is_visible_in_menu' => $visibilityData['isVisibleInMenu'] ? 1 : 0,
            'page_visibility_adheres_rules' => $visibilityData['adheresToRules'] ? 1 : 0,
            'page_visibility_issues' => implode(', ', $visibilityData['issues']),
        ];
    }

    public function getPagesWithVisibilityIssues(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $pages = $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('hidden', 1),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->neq('starttime', 0),
                        $queryBuilder->expr()->gt('starttime', $GLOBALS['EXEC_TIME'] ?? time())
                    ),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->neq('endtime', 0),
                        $queryBuilder->expr()->lt('endtime', $GLOBALS['EXEC_TIME'] ?? time())
                    ),
                    $queryBuilder->expr()->neq('fe_group', ''),
                    $queryBuilder->expr()->eq('nav_hide', 1),
                    $queryBuilder->expr()->eq('no_search', 1),
                    $queryBuilder->expr()->eq('extendToSubpages', 1)
                ),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return $pages;
    }
}
