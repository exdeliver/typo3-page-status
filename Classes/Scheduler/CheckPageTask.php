<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Scheduler;

use Doctrine\DBAL\Types\Types;
use Exception;
use Exdeliver\PageStatus\Domain\Repository\PageStatusRepository;
use Exdeliver\PageStatus\Service\ScreenshotService;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

class CheckPageTask extends AbstractTask
{
    private readonly PageStatusRepository $pageStatusRepository;
    private readonly ScreenshotService $screenshotService;

    public function __construct(
        PageStatusRepository $pageStatusRepository,
        ScreenshotService $screenshotService
    ) {
        $this->pageStatusRepository = $pageStatusRepository;
        $this->screenshotService = $screenshotService;
    }

    public function execute(): bool
    {
        try {
            $pages = $this->getAllPages();
            $successCount = 0;
            $failureCount = 0;

            foreach ($pages as $page) {
                $result = $this->checkPage($page);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }

            $this->logger->info(
                sprintf(
                    'Page status check completed: %d pages checked, %d online, %d offline',
                    count($pages),
                    $successCount,
                    $failureCount
                )
            );

            return true;
        } catch (Exception $e) {
            $this->logger->error('Page status check failed: ' . $e->getMessage());

            return false;
        }
    }

    protected function checkPage(array $page): array
    {
        $url = $this->buildPageUrl($page);
        $checkResult = $this->checkUrl($url);

        $screenshotPath = $this->screenshotService->takeScreenshot($url);

        $pageStatusData = [
            'page_id' => $page['uid'],
            'page_url' => $url,
            'is_online' => $checkResult['success'],
            'http_status_code' => $checkResult['statusCode'],
            'last_check' => date('Y-m-d H:i:s'),
            'screenshot_path' => $screenshotPath,
            'error_message' => $checkResult['details']['error'] ?? '',
        ];

        $this->pageStatusRepository->save($pageStatusData);

        return [
            'pageId' => $page['uid'],
            'success' => $checkResult['success'],
            'statusCode' => $checkResult['statusCode'],
        ];
    }

    protected function checkUrl(string $url): array
    {
        $startTime = microtime(true);
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'TYPO3 PageStatus Scheduler/1.0',
            CURLOPT_NOBODY => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        curl_close($ch);

        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'statusCode' => $httpCode,
            'url' => $url,
            'duration' => $duration,
            'details' => [
                'redirectCount' => $curlInfo['redirect_count'] ?? 0,
                'finalUrl' => $curlInfo['url'] ?? $url,
                'error' => $error,
            ],
        ];
    }

    protected function buildPageUrl(array $page): string
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        try {
            $site = $siteFinder->getSiteByPageId($page['uid']);

            $languageId = (int) ($page['sys_language_uid'] ?? 0);
            $language = $site->getLanguageById($languageId);

            $uri = $site->getRouter()->generateUri(
                (string) $page['uid'],
                ['_language' => $language]
            );

            $url = (string) $uri;

            if (!preg_match('#^https?://#', $url)) {
                $protocol = GeneralUtility::getIndpEnv('TYPO3_SSL') ? 'https' : 'http';
                $host = GeneralUtility::getIndpEnv('HTTP_HOST') ?? 'localhost';
                $url = rtrim($protocol . '://' . $host, '/') . $url;
            }

            return $url;
        } catch (Throwable $e) {
            try {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('pages');

                $pageData = $queryBuilder
                    ->select('slug', 'title')
                    ->from('pages')
                    ->where(
                        $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($page['uid'], Types::INTEGER))
                    )
                    ->executeQuery()
                    ->fetchAssociative();

                $protocol = GeneralUtility::getIndpEnv('TYPO3_SSL') ? 'https' : 'http';
                $host = GeneralUtility::getIndpEnv('HTTP_HOST') ?? 'localhost';
                $baseUrl = $protocol . '://' . $host;

                if ($pageData && !empty($pageData['slug'])) {
                    $slug = trim($pageData['slug'], '/');

                    return rtrim($baseUrl, '/') . '/' . $slug;
                } elseif ($pageData && !empty($pageData['title'])) {
                    $slug = strtolower($pageData['title']);
                    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                    $slug = trim($slug, '-');

                    return rtrim($baseUrl, '/') . '/' . $slug;
                }
            } catch (Throwable $innerE) {
            }

            $protocol = GeneralUtility::getIndpEnv('TYPO3_SSL') ? 'https' : 'http';
            $host = GeneralUtility::getIndpEnv('HTTP_HOST') ?? 'localhost';
            $language = (int) ($page['sys_language_uid'] ?? 0);

            $url = rtrim($protocol . '://' . $host, '/') . '/page-' . $page['uid'];
            if ($language > 0) {
                $url .= '?L=' . $language;
            }

            return $url;
        }
    }

    protected function getAllPages(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        return $queryBuilder
            ->select('uid', 'title', 'alias', 'doktype', 'sys_language_uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                $queryBuilder->expr()->in('doktype', $queryBuilder->createNamedParameter([0, 1, 255], Types::INTEGER_ARRAY))
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function getAdditionalFields(): array
    {
        return [];
    }

    public function saveAdditionalFields(array $customSettings): void
    {
    }
}
