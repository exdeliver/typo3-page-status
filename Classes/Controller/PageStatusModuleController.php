<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Controller;

use Doctrine\DBAL\Types\Types;
use Exception;
use Exdeliver\PageStatus\Domain\Repository\PageStatusRepository;
use Exdeliver\PageStatus\Service\PageVisibilityService;
use Exdeliver\PageStatus\Service\ScreenshotService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PageStatusModuleController
{

    private static array $checkProgress = [];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageStatusRepository $pageStatusRepository,
        private readonly ScreenshotService $screenshotService,
        private readonly PageVisibilityService $pageVisibilityService,
        private readonly ConnectionPool $connectionPool
    ) {
    }

    protected function generateCsrfToken(): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser) {
            $sessionData = $backendUser->getSessionData('page_status_csrf_token');
            if (empty($sessionData)) {
                $sessionData = bin2hex(random_bytes(32));
                $backendUser->setSessionData('page_status_csrf_token', $sessionData);
            }

            return $sessionData;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (!isset($_SESSION['page_status_csrf_token'])) {
            $_SESSION['page_status_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['page_status_csrf_token'];
    }

    protected function validateCsrfToken(ServerRequestInterface $request): bool
    {
        $token = $request->getHeaderLine('X-CSRF-Token');

        if (empty($token)) {
            $parsedBody = $request->getParsedBody();
            $contentType = $request->getHeaderLine('Content-Type');

            if (str_contains($contentType, 'application/json')) {
                $rawBody = (string) $request->getBody();
                $parsedBody = json_decode($rawBody, true) ?: [];
            }

            $token = is_array($parsedBody) ? ($parsedBody['__csrfToken'] ?? '') : '';
        }

        if (empty($token)) {
            return false;
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser) {
            $sessionToken = $backendUser->getSessionData('page_status_csrf_token');
            if (!empty($sessionToken)) {
                return hash_equals($sessionToken, $token);
            }
        }

        if (isset($_SESSION['page_status_csrf_token'])) {
            return hash_equals($_SESSION['page_status_csrf_token'], $token);
        }

        return false;
    }

    protected function getBackendUser(): ?object
    {
        return $GLOBALS['BE_USER'] ?? null;
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $isAjaxRequest = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';

        $parsedBody = $request->getParsedBody();
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string) $request->getBody();
            $parsedBody = json_decode($rawBody, true) ?: [];
        }

        if ($request->getMethod() === 'POST' && $isAjaxRequest && isset($parsedBody['action'])) {
            return $this->handleAjaxRequest($request, $parsedBody);
        }

        $queryParams = $request->getQueryParams();
        $pageId = $queryParams['pageId'] ?? null;
        $filter = $queryParams['filter'] ?? 'all';

        if ($pageId !== null) {
            $pageId = (int) $pageId;
        }

        if ($pageId !== null && $pageId > 0) {
            $record = $this->pageStatusRepository->findByPageId($pageId);
            $pageStatusRecords = $record ? [$record] : [];
        } elseif ($filter === 'online') {
            $pageStatusRecords = $this->pageStatusRepository->findOnlinePages();
        } elseif ($filter === 'offline') {
            $pageStatusRecords = $this->pageStatusRepository->findOfflinePages();
        } elseif ($filter === 'hidden') {
            $pageStatusRecords = $this->pageStatusRepository->findByVisibility(false);
        } elseif ($filter === 'visible') {
            $pageStatusRecords = $this->pageStatusRepository->findByVisibility(true);
        } elseif ($filter === 'issues') {
            $pageStatusRecords = $this->pageStatusRepository->findByVisibilityIssues();
        } else {
            $pageStatusRecords = $this->pageStatusRepository->findAll();
        }

        $pageStatusRecords = $this->enrichWithPageTitles($pageStatusRecords);

        $statistics = $this->pageStatusRepository->getStatistics();

        $pages = $this->getAllPages();

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->assignMultiple([
            'pageStatusRecords' => $pageStatusRecords,
            'statistics' => $statistics,
            'pages' => $pages,
            'filter' => $filter,
            'selectedPageId' => $pageId,
            'screenshotBaseUrl' => GeneralUtility::getIndpEnv('TYPO3_SITE_URL'),
            'csrfToken' => $this->generateCsrfToken(),
        ]);

        return $moduleTemplate->renderResponse('PageStatusModule/Index');
    }

    protected function handleAjaxRequest(ServerRequestInterface $request, array $postData): ResponseInterface
    {
        $action = $postData['action'] ?? '';

        switch ($action) {
            case 'checkAll':
                return $this->handleCheckAllAction($request);

            case 'checkSingle':
                return $this->handleCheckSingleAction($request, $postData);

            case 'checkFailed':
                return $this->handleCheckFailedAction($request, $postData);

            case 'continue':
                return $this->handleContinueAction($request, $postData);

            case 'checkStatus':
                return $this->handleCheckStatusAction($request);

            case 'stop':
                return $this->handleStopAction($request, $postData);

            case 'filter':
            case 'selectPage':

                return $this->handleFilterAction($request, $postData);

            default:
                return new JsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
        }
    }

    protected function handleCheckAllAction(ServerRequestInterface $request): ResponseInterface
    {
        $startTime = microtime(true);
        error_log('=== PageStatus: handleCheckAllAction START ===');

        $sessionId = $request->getHeaderLine('X-Session-ID') ?: $this->generateSessionId();
        error_log('Session ID: ' . $sessionId);

        $contentType = $request->getHeaderLine('Content-Type');
        $filter = 'all';
        $pageId = null;

        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string) $request->getBody();
            $parsedBody = json_decode($rawBody, true) ?: [];
            $filter = $parsedBody['filter'] ?? 'all';
            $pageId = $parsedBody['pageId'] ?? null;
        }

        error_log('Filter: ' . $filter);
        error_log('Page ID: ' . ($pageId ?? 'none'));

        set_time_limit(4);

        $pages = $this->getPagesForCheck($filter, $pageId);
        $totalPages = count($pages);

        error_log('Total pages to check: ' . $totalPages);

        if ($totalPages === 0) {
            error_log('No pages found, returning error');

            return new JsonResponse([
                'success' => false,
                'message' => 'No pages found to check with the current filter.',
            ]);
        }

        $deleted = $this->pageStatusRepository->deleteAll();
        error_log('Cleared ' . $deleted . ' old records before fresh check');

        $registry = GeneralUtility::makeInstance(Registry::class);

        $pagesData = json_encode($pages);
        $registry->set('page_status', 'pages_' . $sessionId, $pagesData);

        $registry->set('page_status', 'filter_' . $sessionId, $filter);

        $this->initializeProgress($sessionId, $totalPages);

        $elapsed = round((microtime(true) - $startTime) * 1000);
        error_log('=== PageStatus: handleCheckAllAction END (took ' . $elapsed . 'ms) ===');

        return new JsonResponse([
            'success' => true,
            'message' => 'Check all initiated',
            'sessionId' => $sessionId,
            'total' => $totalPages,
        ]);
    }

    protected function handleCheckFailedAction(ServerRequestInterface $request, array $postData): ResponseInterface
    {
        $sessionId = $request->getHeaderLine('X-Session-ID') ?: $this->generateSessionId();
        error_log('=== PageStatus: handleCheckFailedAction START ===');
        error_log('Session ID: ' . $sessionId);

        $filter = $postData['filter'] ?? 'all';
        $pageId = $postData['pageId'] ?? 0;
        error_log('Filter: ' . $filter . ', Page ID: ' . $pageId);

        $allPagesInSelection = $this->getPagesForCheck($filter, $pageId === 0 ? null : $pageId);
        error_log('Total pages in selection: ' . count($allPagesInSelection));

        if (empty($allPagesInSelection)) {
            error_log('No pages found in current selection');

            return new JsonResponse([
                'success' => true,
                'message' => 'No pages found in current selection',
                'sessionId' => $sessionId,
                'total' => 0,
                'noFailedPages' => true,
            ]);
        }

        $pageIds = array_column($allPagesInSelection, 'uid');

        $failedPages = $this->pageStatusRepository->findFailedPagesByIds($pageIds);
        error_log('Failed pages found: ' . count($failedPages));

        if (empty($failedPages)) {
            error_log('No failed pages found in current selection');

            return new JsonResponse([
                'success' => true,
                'message' => 'No failed pages found in current selection',
                'sessionId' => $sessionId,
                'total' => 0,
                'noFailedPages' => true,
            ]);
        }

        $pagesForProcessing = array_map(function ($page) {
            return [
                'uid' => $page['page_id'],
                'title' => $page['page_url'] ?? '',
                'slug' => '',
                'doktype' => 0,
                'sys_language_uid' => 0,
                'hidden' => 0,
                'starttime' => 0,
                'endtime' => 0,
                'fe_group' => '',
                'nav_hide' => 0,
            ];
        }, $failedPages);

        $registry = GeneralUtility::makeInstance(Registry::class);
        $registry->set('page_status', 'pages_' . $sessionId, json_encode($pagesForProcessing));

        $this->initializeProgress($sessionId, count($failedPages));

        error_log('=== PageStatus: handleCheckFailedAction END ===');

        return new JsonResponse([
            'success' => true,
            'message' => 'Checking failed pages',
            'sessionId' => $sessionId,
            'total' => count($failedPages),
        ]);
    }

    protected function handleContinueAction(ServerRequestInterface $request, array $postData): ResponseInterface
    {
        $sessionId = $request->getHeaderLine('X-Session-ID') ?: $this->generateSessionId();
        error_log('=== PageStatus: handleContinueAction START ===');
        error_log('Session ID: ' . $sessionId);

        $filter = $postData['filter'] ?? 'all';
        $pageId = $postData['pageId'] ?? 0;
        error_log('Filter: ' . $filter . ', Page ID: ' . $pageId);

        $allPagesInSelection = $this->getPagesForCheck($filter, $pageId === 0 ? null : $pageId);
        error_log('Total pages in selection: ' . count($allPagesInSelection));

        if (empty($allPagesInSelection)) {
            error_log('No pages found in current selection');

            return new JsonResponse([
                'success' => true,
                'message' => 'No pages found in current selection',
                'sessionId' => $sessionId,
                'total' => 0,
                'noNewPages' => true,
            ]);
        }

        $pageIds = array_column($allPagesInSelection, 'uid');

        $newPages = $this->pageStatusRepository->findPagesNotInDatabase($pageIds);
        error_log('New pages (not in database) found: ' . count($newPages));

        if (empty($newPages)) {
            error_log('No new pages found in current selection');

            return new JsonResponse([
                'success' => true,
                'message' => 'All pages from current selection are already in the database',
                'sessionId' => $sessionId,
                'total' => 0,
                'noNewPages' => true,
            ]);
        }

        $pagesForProcessing = array_map(function ($page) {
            return [
                'uid' => $page['page_id'],
                'title' => $page['title'] ?? '',
                'slug' => $page['slug'] ?? '',
                'doktype' => $page['doktype'] ?? 0,
                'sys_language_uid' => $page['sys_language_uid'] ?? 0,
                'hidden' => $page['hidden'] ?? 0,
                'starttime' => $page['starttime'] ?? 0,
                'endtime' => $page['endtime'] ?? 0,
                'fe_group' => $page['fe_group'] ?? '',
                'nav_hide' => $page['nav_hide'] ?? 0,
            ];
        }, $newPages);

        $registry = GeneralUtility::makeInstance(Registry::class);
        $registry->set('page_status', 'pages_' . $sessionId, json_encode($pagesForProcessing));

        $this->initializeProgress($sessionId, count($newPages));

        error_log('=== PageStatus: handleContinueAction END ===');

        return new JsonResponse([
            'success' => true,
            'message' => 'Checking new pages',
            'sessionId' => $sessionId,
            'total' => count($newPages),
        ]);
    }

    protected function handleStopAction(ServerRequestInterface $request, array $postData): ResponseInterface
    {
        $sessionId = $postData['sessionId'] ?? $request->getHeaderLine('X-Session-ID');

        if (!$sessionId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No session ID provided',
            ], 400);
        }

        error_log('=== PageStatus: handleStopAction START ===');
        error_log('Session ID: ' . $sessionId);

        $registry = GeneralUtility::makeInstance(Registry::class);

        $registry->remove('page_status', 'pages_' . $sessionId);
        $registry->remove('page_status', 'progress_' . $sessionId);
        $registry->remove('page_status', 'progress_' . $sessionId . '_expiry');
        $registry->remove('page_status', 'filter_' . $sessionId);

        error_log('=== PageStatus: handleStopAction END ===');

        return new JsonResponse([
            'success' => true,
            'message' => 'Check operation stopped',
        ]);
    }

    protected function handleCheckSingleAction(ServerRequestInterface $request, array $postData): ResponseInterface
    {
        $pageId = $postData['pageId'] ?? 0;

        if ($pageId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid page ID'], 400);
        }

        $page = $this->getPageById($pageId);
        if (!$page) {
            return new JsonResponse(['success' => false, 'message' => 'Page not found'], 404);
        }

        $result = $this->checkSinglePage($page);

        return new JsonResponse([
            'success' => true,
            'result' => $result,
        ]);
    }

    protected function handleCheckStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = $request->getHeaderLine('X-Session-ID');

        if (!$sessionId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No session ID',
            ], 400);
        }

        $registry = GeneralUtility::makeInstance(Registry::class);
        $progress = $this->getProgress($sessionId);
        $isComplete = $progress['isComplete'] ?? false;

        if (!$isComplete) {
            $this->processPagesChunk($sessionId);

            $progress = $this->getProgress($sessionId);
            $isComplete = $progress['isComplete'] ?? false;
        }

        $contentType = $request->getHeaderLine('Content-Type');
        $filter = 'all';

        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string) $request->getBody();
            $parsedBody = json_decode($rawBody, true);
            if ($parsedBody && isset($parsedBody['filter'])) {
                $filter = $parsedBody['filter'];
            }
        } else {
            $filter = $registry->get('page_status', 'filter_' . $sessionId) ?: 'all';
        }

        $response = [
            'success' => true,
            'completed' => $progress['completed'] ?? 0,
            'total' => $progress['total'] ?? 0,
            'isComplete' => $isComplete,
        ];

        $response['pagesHtml'] = $this->getPagesHtml($filter);

        if ($isComplete) {
            error_log('PageStatus: Check complete, returning pagesHtml with filter: ' . $filter);

            $registry->remove('page_status', 'progress_' . $sessionId);
            $registry->remove('page_status', 'progress_' . $sessionId . '_expiry');
            $registry->remove('page_status', 'pages_' . $sessionId);
            $registry->remove('page_status', 'filter_' . $sessionId);
            error_log('PageStatus: Cleaned up Registry entries for session ' . $sessionId);
        }

        return new JsonResponse($response);
    }

    protected function processPagesChunk(string $sessionId): void
    {
        $registry = GeneralUtility::makeInstance(Registry::class);

        $pagesData = $registry->get('page_status', 'pages_' . $sessionId);
        if (!$pagesData) {
            error_log('PageStatus: No pages found in Registry for session ' . $sessionId);

            return;
        }

        $pages = json_decode($pagesData, true);
        if (!is_array($pages)) {
            error_log('PageStatus: Invalid pages data in Registry');

            return;
        }

        $progress = $this->getProgress($sessionId);
        $current = $progress['completed'] ?? 0;
        $total = $progress['total'] ?? count($pages);

        $chunkSize = 3;
        $pagesToProcess = array_slice($pages, $current, $chunkSize);

        error_log('PageStatus: Processing chunk of ' . count($pagesToProcess) . ' pages (from ' . $current . ' of ' . $total . ')');

        $beforeCompleted = $current;

        foreach ($pagesToProcess as $page) {
            try {
                $pageId = (int) $page['uid'];
                error_log('--- PageStatus: Processing page ' . $pageId . ' ---');

                $pageUrl = $this->buildPageUrl($page);
                error_log('Generated URL: ' . $pageUrl);

                $statusCode = $this->checkHttpStatus($pageUrl);
                error_log('HTTP Status Code: ' . $statusCode);

                if ($statusCode >= 500) {
                    error_log('PageStatus: Server error for page ' . $pageId . ', skipping detailed checks');

                    $saveData = [
                        'page_id' => $pageId,
                        'page_url' => $pageUrl,
                        'http_status_code' => $statusCode,
                        'is_online' => 0,
                        'last_check' => date('Y-m-d H:i:s'),
                        'tstamp' => time(),
                        'error_message' => 'Server error during check (HTTP ' . $statusCode . ')',
                    ];

                    $this->pageStatusRepository->save($saveData);
                    error_log('Saved error status for page ' . $pageId);
                    $beforeCompleted++;
                    continue;
                }

                $visibilityData = $this->pageVisibilityService->getPageVisibilityData($pageId);
                $visibilityFields = $this->pageVisibilityService->formatForDatabase($visibilityData);

                $screenshotPath = null;
                if ($statusCode === 200) {
                    try {
                        $screenshotPath = $this->screenshotService->takeScreenshot($pageUrl);
                    } catch (Exception $e) {
                        error_log('PageStatus: Screenshot failed for page ' . $pageId . ': ' . $e->getMessage());
                        $screenshotPath = null;
                    }
                }

                $saveData = [
                    'page_id' => $pageId,
                    'page_url' => $pageUrl,
                    'http_status_code' => $statusCode,
                    'is_online' => $statusCode === 200 ? 1 : 0,
                    'last_check' => date('Y-m-d H:i:s'),
                    'screenshot_path' => $screenshotPath,
                    'tstamp' => time(),
                ];

                $saveData = array_merge($saveData, $visibilityFields);

                error_log('Final saveData for page ' . $pageId . ': ' . json_encode($saveData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                $this->pageStatusRepository->save($saveData);
                error_log('Successfully saved page ' . $pageId);
                $beforeCompleted++;
            } catch (Throwable $e) {
                error_log('PageStatus: Error checking page ' . $page['uid'] . ': ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());

                try {
                    $errorSaveData = [
                        'page_id' => (int) $page['uid'],
                        'page_url' => $this->buildPageUrl($page),
                        'http_status_code' => 500,
                        'is_online' => 0,
                        'last_check' => date('Y-m-d H:i:s'),
                        'tstamp' => time(),
                        'error_message' => substr($e->getMessage(), 0, 255),
                    ];
                    $this->pageStatusRepository->save($errorSaveData);
                    $beforeCompleted++;
                } catch (Throwable $saveError) {
                    error_log('PageStatus: Could not save error record: ' . $saveError->getMessage());
                }

                continue;
            }
        }

        $progress['completed'] = $beforeCompleted;
        $progress['isComplete'] = $beforeCompleted >= $total;
        $registry->set('page_status', 'progress_' . $sessionId, $progress);

        error_log('PageStatus: Updated progress to ' . $beforeCompleted . '/' . $total);
    }

    protected function handleFilterAction(ServerRequestInterface $request, array $postData): ResponseInterface
    {
        $filter = $postData['filter'] ?? 'all';
        $pageId = $postData['pageId'] ?? null;
        $action = $postData['action'] ?? 'filter';

        if ($pageId !== null) {
            $pageId = (int) $pageId;
        }

        if ($action === 'selectPage' && $pageId !== null && $pageId > 0) {
            $record = $this->pageStatusRepository->findByPageId($pageId);
            $pageStatusRecords = $record ? [$record] : [];
        } elseif ($filter === 'online') {
            $pageStatusRecords = $this->pageStatusRepository->findOnlinePages();
        } elseif ($filter === 'offline') {
            $pageStatusRecords = $this->pageStatusRepository->findOfflinePages();
        } elseif ($filter === 'hidden') {
            $pageStatusRecords = $this->pageStatusRepository->findByVisibility(false);
        } elseif ($filter === 'visible') {
            $pageStatusRecords = $this->pageStatusRepository->findByVisibility(true);
        } elseif ($filter === 'issues') {
            $pageStatusRecords = $this->pageStatusRepository->findByVisibilityIssues();
        } else {
            $pageStatusRecords = $this->pageStatusRepository->findAll();
        }

        $pageStatusRecords = $this->enrichWithPageTitles($pageStatusRecords);

        $statistics = $this->pageStatusRepository->getStatistics();

        $html = $this->generateTableRowsHtml($pageStatusRecords);

        return new JsonResponse([
            'success' => true,
            'html' => $html,
            'statistics' => $statistics,
        ]);
    }

    protected function generateTableRowsHtml(array $records): string
    {
        $screenshotBaseUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL');
        $html = '';

        foreach ($records as $record) {
            $rowClass = $record['is_online'] == 1 ? 'table-success' : 'table-danger';
            $statusBadge = $record['is_online'] == 1
                ? '<span class="status-badge badge bg-success">Online</span>'
                : '<span class="status-badge badge bg-danger">Offline</span>';

            $screenshotLink = !empty($record['screenshot_path'])
                ? '<a href="' . htmlspecialchars($screenshotBaseUrl . $record['screenshot_path']) . '" target="_blank" class="btn btn-sm btn-info">View Screenshot</a>'
                : '<span class="text-muted">No screenshot</span>';

            $html .= sprintf(
                '<tr data-page-id="%d" class="%s">
                    <td>%d</td>
                    <td>%s</td>
                    <td><a href="%s" target="_blank" class="text-truncate d-block" style="max-width: 300px;">%s</a></td>
                    <td>%s</td>
                    <td><span class="http-status-code">%d</span></td>
                    <td><span class="last-checked">%s</span></td>
                    <td>%s</td>
                    <td>
                        <button class="btn btn-sm btn-primary check-single-btn" data-page-id="%d">
                            Recheck
                        </button>
                    </td>
                </tr>',
                (int) $record['page_id'],
                htmlspecialchars($rowClass),
                (int) $record['page_id'],
                htmlspecialchars($record['page_title']),
                htmlspecialchars($record['page_url']),
                htmlspecialchars($record['page_url']),
                $statusBadge,
                (int) $record['http_status_code'],
                !empty($record['last_check']) ? htmlspecialchars($record['last_check']) : 'Never',
                $screenshotLink,
                (int) $record['page_id']
            );
        }

        return $html;
    }

    public function checkAllAction(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = $request->getHeaderLine('X-Session-ID') ?: session_id();
        $pages = $this->getAllPages();
        $totalPages = count($pages);

        if ($totalPages === 0) {
            self::$checkProgress[$sessionId] = [
                'current' => 0,
                'total' => 0,
                'successCount' => 0,
                'isComplete' => true,
                'results' => [],
            ];

            return new JsonResponse([
                'success' => true,
                'results' => [],
                'message' => 'No pages to check',
            ]);
        }

        self::$checkProgress[$sessionId] = [
            'current' => 0,
            'total' => $totalPages,
            'successCount' => 0,
            'isComplete' => false,
            'results' => [],
        ];

        $results = [];
        $currentStep = 0;
        $successCount = 0;

        foreach ($pages as $page) {
            $currentStep++;

            self::$checkProgress[$sessionId]['current'] = $currentStep;

            $result = $this->checkPage($page);
            $results[] = $result;

            if ($result['success']) {
                $successCount++;
            }

            self::$checkProgress[$sessionId]['successCount'] = $successCount;
            self::$checkProgress[$sessionId]['results'][] = $result;
        }

        self::$checkProgress[$sessionId]['isComplete'] = true;

        return new JsonResponse([
            'success' => true,
            'results' => $results,
            'total' => $totalPages,
        ]);
    }

    public function checkStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = $request->getHeaderLine('X-Session-ID') ?: session_id();

        $progress = self::$checkProgress[$sessionId] ?? [
            'current' => 0,
            'total' => 0,
            'successCount' => 0,
            'isComplete' => true,
            'results' => [],
        ];

        return new JsonResponse([
            'isComplete' => $progress['isComplete'],
            'progress' => [
                'current' => $progress['current'],
                'total' => $progress['total'],
            ],
            'successCount' => $progress['successCount'],
            'total' => $progress['total'],
        ]);
    }

    public function checkSingleAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string) $request->getBody();
            $parsedBody = json_decode($rawBody, true) ?: [];
        }

        $pageId = (int) ($parsedBody['pageId'] ?? 0);

        if ($pageId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid page ID']);
        }

        $page = $this->getPageById($pageId);

        if (!$page) {
            return new JsonResponse(['success' => false, 'message' => 'Page not found']);
        }

        $result = $this->checkPage($page);

        return new JsonResponse($result);
    }

    protected function checkPage(array $page): array
    {
        $url = $this->buildPageUrl($page);
        $checkResult = $this->checkUrl($url);

        $screenshotPath = $this->screenshotService->takeScreenshot($url);

        $visibilityData = $this->pageVisibilityService->getPageVisibilityData((int) $page['uid']);
        $visibilityFields = $this->pageVisibilityService->formatForDatabase($visibilityData);

        $pageStatusData = [
            'page_id' => $page['uid'],
            'page_url' => $url,
            'is_online' => $checkResult['success'],
            'http_status_code' => $checkResult['statusCode'],
            'last_check' => date('Y-m-d H:i:s'),
            'screenshot_path' => $screenshotPath,
            'error_message' => $checkResult['details']['error'] ?? '',
        ];

        $pageStatusData = array_merge($pageStatusData, $visibilityFields);

        $this->pageStatusRepository->save($pageStatusData);

        return [
            'pageId' => $page['uid'],
            'pageTitle' => $page['title'] ?? 'Untitled',
            'url' => $url,
            'success' => $checkResult['success'],
            'statusCode' => $checkResult['statusCode'],
            'duration' => $checkResult['duration'],
            'screenshotPath' => $screenshotPath,
            'errorMessage' => $checkResult['details']['error'] ?? '',

            'visibility' => $visibilityData,
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
            CURLOPT_USERAGENT => 'TYPO3 PageStatus/1.0',
            CURLOPT_NOBODY => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        curl_close($ch);

        error_log('PageStatus checkUrl: ' . $url . ' -> HTTP ' . $httpCode . ' (error: ' . $error . ')');

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

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $requestBase = $protocol . '://' . $host;

        try {
            $site = $siteFinder->getSiteByPageId($page['uid']);
            $url = $this->generateUrlFromSite($site, $page, $requestBase);

            error_log('PageStatus buildPageUrl: Page ' . $page['uid'] . ' -> ' . $url);

            return $url;
        } catch (Throwable $e) {
            try {
                $allSites = $siteFinder->getAllSites();
                if (!empty($allSites)) {
                    foreach ($allSites as $site) {
                        try {
                            $url = $this->generateUrlFromSite($site, $page, $requestBase);

                            error_log('PageStatus buildPageUrl (found site): Page ' . $page['uid'] . ' -> ' . $url);

                            return $url;
                        } catch (Throwable $innerE) {
                            continue;
                        }
                    }
                }
            } catch (Throwable $innerE) {
            }

            $url = $this->buildFallbackUrl($page, $requestBase);
            error_log('PageStatus buildPageUrl (fallback): Page ' . $page['uid'] . ' -> ' . $url);

            return $url;
        }
    }

    protected function generateUrlFromSite($site, array $page, string $requestBase): string
    {
        $language = $page['sys_language_uid'] ?? 0;

        $languageAspect = null;
        foreach ($site->getLanguages() as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === $language) {
                $languageAspect = $siteLanguage;
                break;
            }
        }

        if (!$languageAspect) {
            $languageAspect = $site->getDefaultLanguage();
        }

        $uri = $site->getRouter()->generateUri(
            (int) $page['uid'],
            ['_language' => $languageAspect]
        );

        $url = (string) $uri;

        if (!preg_match('#^https?://#', $url)) {
            $url = rtrim($requestBase, '/') . $url;
        }

        return $url;
    }

    protected function buildFallbackUrl(array $page, string $requestBase): string
    {
        $pageUid = (int) $page['uid'];

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $pageData = $queryBuilder
                ->select('slug', 'title', 'doktype')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\Types::INTEGER))
                )
                ->executeQuery()
                ->fetchAssociative();

            if ($pageData && !empty($pageData['slug'])) {
                $slug = trim($pageData['slug'], '/');

                return rtrim($requestBase, '/') . '/' . $slug;
            } elseif ($pageData && !empty($pageData['title'])) {
                $slug = strtolower($pageData['title']);
                $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                $slug = trim($slug, '-');

                return rtrim($requestBase, '/') . '/' . $slug;
            }
        } catch (Throwable $e) {
            error_log('PageStatus buildFallbackUrl: Could not fetch page data for page ' . $pageUid . ': ' . $e->getMessage());
        }

        return rtrim($requestBase, '/') . '/page-' . $pageUid;
    }

    protected function getPageById(int $pageId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        return $queryBuilder
            ->select('uid', 'title', 'doktype', 'sys_language_uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Types::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Types::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative() ?: null;
    }

    protected function getAllPages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        return $queryBuilder
            ->select('uid', 'title', 'slug', 'doktype', 'sys_language_uid', 'hidden', 'starttime', 'endtime', 'fe_group', 'nav_hide')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                $queryBuilder->expr()->in('doktype', $queryBuilder->createNamedParameter([0, 1, 255], Types::INTEGER))
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    protected function getPagesForCheck(string $filter = 'all', ?int $pageId = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb = $queryBuilder
            ->select('p.uid', 'p.title', 'p.slug', 'p.doktype', 'p.sys_language_uid', 'p.hidden', 'p.starttime', 'p.endtime', 'p.fe_group', 'p.nav_hide')
            ->from('pages', 'p')
            ->where(
                $queryBuilder->expr()->eq('p.deleted', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                $queryBuilder->expr()->in('p.doktype', $queryBuilder->createNamedParameter([0, 1, 255], Types::INTEGER))
            );

        if ($pageId !== null && $pageId > 0) {
            $qb->andWhere(
                $queryBuilder->expr()->eq('p.uid', $queryBuilder->createNamedParameter($pageId, Types::INTEGER))
            );
        } else {
            switch ($filter) {
                case 'online':

                    $now = time();
                    $qb->andWhere(
                        $queryBuilder->expr()->eq('p.hidden', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                        $queryBuilder->expr()->or(
                            $queryBuilder->expr()->eq('p.starttime', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                            $queryBuilder->expr()->lte('p.starttime', $queryBuilder->createNamedParameter($now, Types::INTEGER))
                        ),
                        $queryBuilder->expr()->or(
                            $queryBuilder->expr()->eq('p.endtime', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                            $queryBuilder->expr()->gte('p.endtime', $queryBuilder->createNamedParameter($now, Types::INTEGER))
                        ),
                        $queryBuilder->expr()->eq('p.fe_group', $queryBuilder->createNamedParameter('', Types::STRING))
                    );
                    break;
                case 'offline':

                    $now = time();
                    $qb->andWhere(
                        $queryBuilder->expr()->or(
                            $queryBuilder->expr()->eq('p.hidden', $queryBuilder->createNamedParameter(1, Types::INTEGER)),
                            $queryBuilder->expr()->and(
                                $queryBuilder->expr()->neq('p.starttime', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                                $queryBuilder->expr()->gt('p.starttime', $queryBuilder->createNamedParameter($now, Types::INTEGER))
                            ),
                            $queryBuilder->expr()->and(
                                $queryBuilder->expr()->neq('p.endtime', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                                $queryBuilder->expr()->lt('p.endtime', $queryBuilder->createNamedParameter($now, Types::INTEGER))
                            ),
                            $queryBuilder->expr()->neq('p.fe_group', $queryBuilder->createNamedParameter('', Types::STRING))
                        )
                    );
                    break;
                case 'hidden':

                    $qb->andWhere(
                        $queryBuilder->expr()->eq('p.hidden', $queryBuilder->createNamedParameter(1, Types::INTEGER))
                    );
                    break;
                case 'visible':

                    $qb->andWhere(
                        $queryBuilder->expr()->eq('p.hidden', $queryBuilder->createNamedParameter(0, Types::INTEGER))
                    );
                    break;
                case 'issues':

                    $qb->andWhere(
                        $queryBuilder->expr()->eq('p.hidden', $queryBuilder->createNamedParameter(1, Types::INTEGER))
                    );
                    break;
                case 'failed':

                    $qb->leftJoin(
                        'p',
                        'tx_pagestatus_domain_model_pagestatus',
                        'ps',
                        $queryBuilder->expr()->eq('p.uid', 'ps.page_id')
                    )->andWhere(
                            $queryBuilder->expr()->or(
                                $queryBuilder->expr()->eq('ps.is_online', $queryBuilder->createNamedParameter(0, Types::INTEGER)),
                                $queryBuilder->expr()->isNull('ps.is_online')
                            )
                        );
                    break;
                case 'all':
                default:

                    break;
            }
        }

        $result = $qb
            ->orderBy('p.sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function ($row) {
            return [
                'uid' => $row['uid'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'doktype' => $row['doktype'],
                'sys_language_uid' => $row['sys_language_uid'],
                'hidden' => $row['hidden'],
                'starttime' => $row['starttime'],
                'endtime' => $row['endtime'],
                'fe_group' => $row['fe_group'],
                'nav_hide' => $row['nav_hide'],
            ];
        }, $result);
    }

    protected function queuePageChecks(array $pages, string $sessionId): void
    {
        $progress = [
            'sessionId' => $sessionId,
            'total' => count($pages),
            'completed' => 0,
            'startTime' => time(),
        ];

        $_SESSION['page_status_progress_' . $sessionId] = $progress;
        $_SESSION['page_status_pages_' . $sessionId] = $pages;

        $this->processPageChecks($sessionId);
    }

    protected function initializeProgress(string $sessionId, int $total): void
    {
        $registry = GeneralUtility::makeInstance(Registry::class);
        $progress = [
            'total' => $total,
            'completed' => 0,
            'isComplete' => false,
            'startTime' => time(),
        ];
        $registry->set('page_status', 'progress_' . $sessionId, $progress);

        $registry->set('page_status', 'progress_' . $sessionId . '_expiry', time() + 3600);

        error_log('PageStatus: Initialized progress for session ' . $sessionId . ' with total ' . $total);
    }

    public function processPageChecksChunked(array $pages, string $sessionId): void
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $registry = GeneralUtility::makeInstance(Registry::class);
        $total = count($pages);
        $chunkSize = 10;

        error_log('PageStatus: Starting to process ' . $total . ' pages in chunks of ' . $chunkSize);

        for ($i = 0; $i < $total; $i += $chunkSize) {
            $chunk = array_slice($pages, $i, $chunkSize);

            foreach ($chunk as $page) {
                try {
                    $pageId = (int) $page['uid'];

                    error_log('--- PageStatus: Processing page ' . $pageId . ' ---');
                    error_log('Page data: ' . json_encode($page, JSON_UNESCAPED_SLASHES));

                    $pageUrl = $this->buildPageUrl($page);
                    error_log('Generated URL: ' . $pageUrl);

                    $statusCode = $this->checkHttpStatus($pageUrl);
                    error_log('HTTP Status Code: ' . $statusCode);

                    if ($statusCode >= 500) {
                        error_log('PageStatus: Server error for page ' . $pageId . ', skipping detailed checks');

                        $saveData = [
                            'page_id' => $pageId,
                            'page_url' => $pageUrl,
                            'http_status_code' => $statusCode,
                            'is_online' => 0,
                            'last_check' => date('Y-m-d H:i:s'),
                            'tstamp' => time(),
                            'error_message' => 'Server error during check (HTTP ' . $statusCode . ')',
                        ];

                        $this->pageStatusRepository->save($saveData);
                        error_log('Saved error status for page ' . $pageId);
                        continue;
                    }

                    $visibilityData = $this->pageVisibilityService->getPageVisibilityData($pageId);
                    error_log('Visibility data: ' . json_encode($visibilityData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                    $visibilityFields = $this->pageVisibilityService->formatForDatabase($visibilityData);
                    error_log('Visibility fields for DB: ' . json_encode($visibilityFields, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                    $screenshotPath = null;
                    if ($statusCode === 200) {
                        try {
                            $screenshotPath = $this->screenshotService->takeScreenshot($pageUrl);
                        } catch (Exception $e) {
                            error_log('PageStatus: Screenshot failed for page ' . $pageId . ': ' . $e->getMessage());
                            $screenshotPath = null;
                        }
                    }

                    $saveData = [
                        'page_id' => $pageId,
                        'page_url' => $pageUrl,
                        'http_status_code' => $statusCode,
                        'is_online' => $statusCode === 200 ? 1 : 0,
                        'last_check' => date('Y-m-d H:i:s'),
                        'screenshot_path' => $screenshotPath,
                        'tstamp' => time(),
                    ];

                    $saveData = array_merge($saveData, $visibilityFields);

                    error_log('Final saveData for page ' . $pageId . ': ' . json_encode($saveData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                    $this->pageStatusRepository->save($saveData);

                    error_log('Successfully saved page ' . $pageId);
                } catch (Exception $e) {
                    error_log('PageStatus: Error checking page ' . $page['uid'] . ': ' . $e->getMessage());
                    error_log('Stack trace: ' . $e->getTraceAsString());

                    try {
                        $errorSaveData = [
                            'page_id' => (int) $page['uid'],
                            'page_url' => $this->buildPageUrl($page),
                            'http_status_code' => 500,
                            'is_online' => 0,
                            'last_check' => date('Y-m-d H:i:s'),
                            'tstamp' => time(),
                            'error_message' => substr($e->getMessage(), 0, 255),
                        ];
                        $this->pageStatusRepository->save($errorSaveData);
                    } catch (Exception $saveError) {
                        error_log('PageStatus: Could not save error record: ' . $saveError->getMessage());
                    }

                    continue;
                }
            }

            $completed = min($i + $chunkSize, $total);
            $progress = $registry->get('page_status', 'progress_' . $sessionId);
            if ($progress) {
                $progress['completed'] = $completed;
                $progress['isComplete'] = $completed >= $total;
                $registry->set('page_status', 'progress_' . $sessionId, $progress);
                error_log('PageStatus: Updated progress: ' . $completed . '/' . $total);
            } else {
                error_log('PageStatus: Could not retrieve progress for session ' . $sessionId);
            }

            usleep(10000);
        }

        error_log('PageStatus: Finished processing all pages');
    }

    protected function processPageChecks(string $sessionId): void
    {
        error_log('=== PageStatus: processPageChecks (LEGACY) START ===');

        $pages = $_SESSION['page_status_pages_' . $sessionId] ?? [];
        $progress = $_SESSION['page_status_progress_' . $sessionId] ?? [];

        error_log('Pages from session: ' . count($pages));
        error_log('Progress from session: ' . json_encode($progress, JSON_UNESCAPED_SLASHES));

        if (empty($pages)) {
            error_log('No pages to process, exiting');

            return;
        }

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $completed = 0;

        foreach ($pages as $page) {
            try {
                $pageId = (int) $page['uid'];
                error_log('--- Processing page ' . $pageId . ' (LEGACY) ---');

                $pageUrl = $this->buildPageUrl($page);
                error_log('Generated URL: ' . $pageUrl);

                $statusCode = $this->checkHttpStatus($pageUrl);
                error_log('HTTP Status: ' . $statusCode);

                if ($statusCode >= 500) {
                    error_log('PageStatus: Server error for page ' . $pageId . ', skipping detailed checks (LEGACY)');

                    $saveData = [
                        'page_id' => $pageId,
                        'page_url' => $pageUrl,
                        'http_status_code' => $statusCode,
                        'is_online' => 0,
                        'last_check' => date('Y-m-d H:i:s'),
                        'tstamp' => time(),
                        'error_message' => 'Server error during check (HTTP ' . $statusCode . ')',
                    ];

                    $this->pageStatusRepository->save($saveData);
                    error_log('Saved error status for page ' . $pageId);
                    $completed++;
                    $progress['completed'] = $completed;
                    $_SESSION['page_status_progress_' . $sessionId] = $progress;
                    continue;
                }

                $visibilityData = $this->pageVisibilityService->getPageVisibilityData($pageId);
                $visibilityFields = $this->pageVisibilityService->formatForDatabase($visibilityData);

                $screenshotPath = null;
                if ($statusCode === 200) {
                    try {
                        $screenshotPath = $this->screenshotService->takeScreenshot($pageUrl);
                    } catch (Exception $e) {
                        error_log('PageStatus: Screenshot failed for page ' . $pageId . ': ' . $e->getMessage());
                        $screenshotPath = null;
                    }
                }

                $saveData = [
                    'page_id' => $pageId,
                    'page_url' => $pageUrl,
                    'http_status_code' => $statusCode,
                    'is_online' => $statusCode === 200 ? 1 : 0,
                    'last_check' => date('Y-m-d H:i:s'),
                    'screenshot_path' => $screenshotPath,
                    'tstamp' => time(),
                ];

                $saveData = array_merge($saveData, $visibilityFields);

                error_log('Save data: ' . json_encode($saveData, JSON_UNESCAPED_SLASHES));
                $this->pageStatusRepository->save($saveData);

                $completed++;
                $progress['completed'] = $completed;
                $_SESSION['page_status_progress_' . $sessionId] = $progress;
            } catch (Exception $e) {
                error_log('ERROR checking page ' . $page['uid'] . ': ' . $e->getMessage());

                try {
                    $errorSaveData = [
                        'page_id' => (int) $page['uid'],
                        'page_url' => $this->buildPageUrl($page),
                        'http_status_code' => 500,
                        'is_online' => 0,
                        'last_check' => date('Y-m-d H:i:s'),
                        'tstamp' => time(),
                        'error_message' => substr($e->getMessage(), 0, 255),
                    ];
                    $this->pageStatusRepository->save($errorSaveData);
                } catch (Exception $saveError) {
                    error_log('PageStatus: Could not save error record: ' . $saveError->getMessage());
                }

                continue;
            }
        }

        $progress['completed'] = $progress['total'];
        $progress['isComplete'] = true;
        $_SESSION['page_status_progress_' . $sessionId] = $progress;

        error_log('=== PageStatus: processPageChecks (LEGACY) END ===');
    }

    protected function checkSinglePage(array $page): array
    {
        error_log('=== PageStatus: checkSinglePage START ===');
        $pageId = (int) $page['uid'];
        error_log('Checking single page ID: ' . $pageId);
        error_log('Page data: ' . json_encode($page, JSON_UNESCAPED_SLASHES));

        try {
            $pageUrl = $this->buildPageUrl($page);
            error_log('Generated URL for single page: ' . $pageUrl);

            $statusCode = $this->checkHttpStatus($pageUrl);
            error_log('HTTP Status Code for single page: ' . $statusCode);

            if ($statusCode >= 500) {
                error_log('PageStatus: Server error for page ' . $pageId . ', skipping detailed checks (SINGLE)');

                $saveData = [
                    'page_id' => $pageId,
                    'page_url' => $pageUrl,
                    'http_status_code' => $statusCode,
                    'is_online' => 0,
                    'last_check' => date('Y-m-d H:i:s'),
                    'tstamp' => time(),
                    'error_message' => 'Server error during check (HTTP ' . $statusCode . ')',
                ];

                $this->pageStatusRepository->save($saveData);

                return [
                    'pageId' => $pageId,
                    'statusCode' => $statusCode,
                    'success' => false,
                    'pageUrl' => $pageUrl,
                    'error' => 'Server error during check (HTTP ' . $statusCode . ')',
                ];
            }

            $visibilityData = $this->pageVisibilityService->getPageVisibilityData($pageId);
            error_log('Visibility data for single page: ' . json_encode($visibilityData, JSON_UNESCAPED_SLASHES));

            $visibilityFields = $this->pageVisibilityService->formatForDatabase($visibilityData);

            $screenshotPath = null;
            if ($statusCode === 200) {
                try {
                    $screenshotPath = $this->screenshotService->takeScreenshot($pageUrl);
                } catch (Exception $e) {
                    error_log('PageStatus: Screenshot failed for page ' . $pageId . ': ' . $e->getMessage());
                    $screenshotPath = null;
                }
            }

            $saveData = [
                'page_id' => $pageId,
                'page_url' => $pageUrl,
                'http_status_code' => $statusCode,
                'is_online' => $statusCode === 200 ? 1 : 0,
                'last_check' => date('Y-m-d H:i:s'),
                'screenshot_path' => $screenshotPath,
                'tstamp' => time(),
            ];

            $saveData = array_merge($saveData, $visibilityFields);

            error_log('Save data for single page: ' . json_encode($saveData, JSON_UNESCAPED_SLASHES));
            $this->pageStatusRepository->save($saveData);

            error_log('=== PageStatus: checkSinglePage END ===');

            return [
                'pageId' => $pageId,
                'statusCode' => $statusCode,
                'success' => true,
                'pageUrl' => $pageUrl,
                'visibility' => $visibilityData,
            ];
        } catch (Exception $e) {
            error_log('=== PageStatus: checkSinglePage ERROR ===');
            error_log('Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            try {
                $errorSaveData = [
                    'page_id' => $pageId,
                    'page_url' => $this->buildPageUrl($page),
                    'http_status_code' => 500,
                    'is_online' => 0,
                    'last_check' => date('Y-m-d H:i:s'),
                    'tstamp' => time(),
                    'error_message' => substr($e->getMessage(), 0, 255),
                ];
                $this->pageStatusRepository->save($errorSaveData);
            } catch (Exception $saveError) {
                error_log('PageStatus: Could not save error record: ' . $saveError->getMessage());
            }

            return [
                'pageId' => $pageId,
                'statusCode' => 0,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getProgress(string $sessionId): array
    {
        $registry = GeneralUtility::makeInstance(Registry::class);
        $progress = $registry->get('page_status', 'progress_' . $sessionId);

        if ($progress) {
            return $progress;
        }

        return [
            'completed' => 0,
            'total' => 0,
            'isComplete' => false,
        ];
    }

    protected function cleanupExpiredRegistryEntries(): void
    {
        $registry = GeneralUtility::makeInstance(Registry::class);
        $now = time();
    }

    protected function generateSessionId(): string
    {
        return 'sess_' . bin2hex(random_bytes(8)) . '_' . time();
    }

    protected function getPagesHtml(string $filter = 'all'): string
    {
        if ($filter === 'online') {
            $records = $this->pageStatusRepository->findOnlinePages();
        } elseif ($filter === 'offline') {
            $records = $this->pageStatusRepository->findOfflinePages();
        } elseif ($filter === 'hidden') {
            $records = $this->pageStatusRepository->findByVisibility(false);
        } elseif ($filter === 'visible') {
            $records = $this->pageStatusRepository->findByVisibility(true);
        } elseif ($filter === 'issues') {
            $records = $this->pageStatusRepository->findByVisibilityIssues();
        } else {
            $records = $this->pageStatusRepository->findAll();
        }

        error_log('PageStatus: getPagesHtml with filter "' . $filter . '" found ' . count($records) . ' records');

        $records = $this->enrichWithPageTitles($records);

        return $this->generateTableRowsHtml($records);
    }

    public function checkHttpStatus(string $url): int
    {
        $ch = curl_init($url);

        if ($ch === false) {
            error_log('PageStatus: Failed to initialize cURL for URL: ' . $url);

            return 0;
        }

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorNo = curl_errno($ch);
        $error = curl_error($ch);

        curl_close($ch);

        if ($errorNo !== 0) {
            error_log('PageStatus: cURL error for URL ' . $url . ': ' . $error . ' (code: ' . $errorNo . ')');

            if ($errorNo === CURLE_OPERATION_TIMEDOUT || $errorNo === CURLE_OPERATION_TIMEOUTED) {
                error_log('PageStatus: Request timeout for ' . $url);

                return 504;
            } elseif ($errorNo === CURLE_COULDNT_CONNECT || $errorNo === CURLE_COULDNT_RESOLVE_HOST) {
                error_log('PageStatus: Connection failed for ' . $url);

                return 503;
            } else {
                error_log('PageStatus: Generic cURL error for ' . $url);

                return 500;
            }
        }

        if ($statusCode >= 400) {
            error_log('PageStatus: HTTP error ' . $statusCode . ' for URL: ' . $url);
        }

        return $statusCode ?: 0;
    }

    protected function enrichWithPageTitles(array $records): array
    {
        if (empty($records)) {
            return $records;
        }

        $pageIds = array_column($records, 'page_id');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $pages = $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pageIds, Types::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $pageTitles = [];
        foreach ($pages as $page) {
            $pageTitles[$page['uid']] = $page['title'];
        }

        foreach ($records as &$record) {
            $record['page_title'] = $pageTitles[$record['page_id']] ?? 'Page ' . $record['page_id'];
        }

        return $records;
    }
}
