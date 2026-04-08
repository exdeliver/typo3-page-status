<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Domain\Repository;

use Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageStatusRepository
{
    protected string $tableName = 'tx_pagestatus_domain_model_pagestatus';

    protected function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->tableName);
    }

    public function findAll(): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->orderBy('last_check', 'DESC')
            ->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function findByPageId(int $pageId): ?array
    {
        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('page_id', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT))
            )
            ->orderBy('last_check', 'DESC')
            ->setMaxResults(1)
            ->executeQuery();

        $result = $statement->fetchAssociative();

        return $result !== false ? (array) $result : null;
    }

    public function findOnlinePages(): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('is_online', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->orderBy('last_check', 'DESC')
            ->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function findOfflinePages(): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('is_online', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('last_check', 'DESC')
            ->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function findFailedPages(): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('is_online', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('last_check', 'DESC')
            ->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function findFailedPagesByIds(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('is_online', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('page_id', $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY))
            )
            ->orderBy('last_check', 'DESC')
            ->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function findPagesNotInDatabase(array $pageIds = []): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $qb = $queryBuilder
            ->select('p.uid as page_id', 'p.title', 'p.slug', 'p.doktype', 'p.sys_language_uid', 'p.hidden', 'p.starttime', 'p.endtime', 'p.fe_group', 'p.nav_hide')
            ->from('pages', 'p')
            ->leftJoin(
                'p',
                $this->tableName,
                'ps',
                $queryBuilder->expr()->eq('p.uid', 'ps.page_id')
            )
            ->where(
                $queryBuilder->expr()->eq('p.deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('p.doktype', $queryBuilder->createNamedParameter([0, 1, 255], Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->isNull('ps.page_id')
            );

        if (!empty($pageIds)) {
            $qb->andWhere(
                $queryBuilder->expr()->in('p.uid', $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY))
            );
        }

        $qb->orderBy('p.sorting');

        $statement = $qb->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function save(array $data): int
    {
        $queryBuilder = $this->getQueryBuilder();
        $existingRecord = $this->findByPageId((int) $data['page_id']);

        $data['tstamp'] = time();

        error_log('=== PageStatusRepository::save() ===');
        error_log('Incoming data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        error_log('Existing record: ' . ($existingRecord ? 'YES (uid=' . $existingRecord['uid'] . ')' : 'NO'));

        if ($existingRecord) {
            error_log('Action: UPDATE existing record');

            $queryBuilder
                ->update($this->tableName)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($existingRecord['uid'], Connection::PARAM_INT))
                )
                ->set('page_url', $data['page_url'])
                ->set('is_online', $data['is_online'] ? 1 : 0)
                ->set('http_status_code', $data['http_status_code'])
                ->set('last_check', $data['last_check'])
                ->set('screenshot_path', $data['screenshot_path'] ?? '')
                ->set('error_message', $data['error_message'] ?? '')
                ->set('tstamp', $data['tstamp'])
                ->set('page_is_visible', $data['page_is_visible'] ?? 1)
                ->set('page_hidden', $data['page_hidden'] ?? 0)
                ->set('page_starttime', $data['page_starttime'] ?? 0)
                ->set('page_endtime', $data['page_endtime'] ?? 0)
                ->set('page_fe_group', $data['page_fe_group'] ?? '')
                ->set('page_is_visible_in_menu', $data['page_is_visible_in_menu'] ?? 1)
                ->set('page_visibility_adheres_rules', $data['page_visibility_adheres_rules'] ?? 1)
                ->set('page_visibility_issues', $data['page_visibility_issues'] ?? '');

            $sql = $queryBuilder->getSQL();
            $params = $queryBuilder->getParameters();
            error_log('UPDATE SQL: ' . $sql);
            error_log('UPDATE params: ' . json_encode($params, JSON_UNESCAPED_SLASHES));

            try {
                $affectedRows = $queryBuilder->executeStatement();
                error_log('UPDATE result: ' . $affectedRows . ' rows affected');
            } catch (Exception $e) {
                error_log('UPDATE ERROR: ' . $e->getMessage());
                throw $e;
            }

            return (int) $existingRecord['uid'];
        }

        error_log('Action: INSERT new record');

        $data['crdate'] = time();
        $data['pid'] = 0;

        if (!isset($data['screenshot_path'])) {
            $data['screenshot_path'] = '';
        }
        if (!isset($data['error_message'])) {
            $data['error_message'] = '';
        }
        if (!isset($data['page_is_visible'])) {
            $data['page_is_visible'] = 1;
        }
        if (!isset($data['page_hidden'])) {
            $data['page_hidden'] = 0;
        }
        if (!isset($data['page_starttime'])) {
            $data['page_starttime'] = 0;
        }
        if (!isset($data['page_endtime'])) {
            $data['page_endtime'] = 0;
        }
        if (!isset($data['page_fe_group'])) {
            $data['page_fe_group'] = '';
        }
        if (!isset($data['page_is_visible_in_menu'])) {
            $data['page_is_visible_in_menu'] = 1;
        }
        if (!isset($data['page_visibility_adheres_rules'])) {
            $data['page_visibility_adheres_rules'] = 1;
        }
        if (!isset($data['page_visibility_issues'])) {
            $data['page_visibility_issues'] = '';
        }

        $connection = $queryBuilder->getConnection();

        error_log('INSERT data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        try {
            $connection->insert($this->tableName, $data);
            $newId = (int) $connection->lastInsertId();
            error_log('INSERT result: New ID = ' . $newId);

            return $newId;
        } catch (Exception $e) {
            error_log('INSERT ERROR: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteOldRecords(int $days): int
    {
        $queryBuilder = $this->getQueryBuilder();
        $cutoffDate = time() - ($days * 24 * 60 * 60);

        $affectedRows = $queryBuilder
            ->delete($this->tableName)
            ->where(
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($cutoffDate, Connection::PARAM_INT))
            )
            ->executeStatement();

        return $affectedRows;
    }

    public function deleteAll(): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($this->tableName);

        return $connection->truncate($this->tableName);
    }

    public function findByVisibility(bool $isVisible): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('page_is_visible', $queryBuilder->createNamedParameter($isVisible ? 1 : 0, Connection::PARAM_INT))
            )
            ->orderBy('last_check', 'DESC')
            ->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function findByVisibilityIssues(): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $statement = $queryBuilder
            ->select('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq('page_visibility_adheres_rules', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('last_check', 'DESC')
            ->executeQuery();

        return $statement->fetchAllAssociative();
    }

    public function getStatistics(): array
    {
        $queryBuilder = $this->getQueryBuilder();

        $totalQuery = clone $queryBuilder;
        $total = $totalQuery
            ->count('uid')
            ->from($this->tableName)
            ->executeQuery()
            ->fetchOne();

        $onlineQuery = clone $queryBuilder;
        $online = $onlineQuery
            ->count('uid')
            ->from($this->tableName)
            ->where(
                $onlineQuery->expr()->eq('is_online', $onlineQuery->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();

        $offline = (int) $total - (int) $online;

        return [
            'total' => (int) $total,
            'online' => (int) $online,
            'offline' => $offline,
        ];
    }
}
