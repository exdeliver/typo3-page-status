<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Command;

use Doctrine\DBAL\Types\Types;
use Exception;
use Exdeliver\PageStatus\Domain\Repository\PageStatusRepository;
use Exdeliver\PageStatus\Service\ScreenshotService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageRepository;

#[AsCommand(
    name: 'pagestatus:check',
    description: 'Check if a TYPO3 page is accessible and save status information'
)]
class CheckPageCommand extends Command
{
    public function __construct(
        private readonly ?PageStatusRepository $pageStatusRepository = null,
        private readonly ?ScreenshotService $screenshotService = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'pageId',
                InputArgument::OPTIONAL,
                'The TYPO3 page ID to check (optional, checks all pages if not provided)'
            )
            ->addArgument(
                'url',
                InputArgument::OPTIONAL,
                'The full URL to check (overrides pageId if provided)'
            )
            ->addOption(
                'save',
                null,
                InputOption::VALUE_NONE,
                'Save the result to the database'
            )
            ->addOption(
                'screenshot',
                null,
                InputOption::VALUE_NONE,
                'Take a screenshot of the page'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = $input->getArgument('pageId');
        $url = $input->getArgument('url');
        $save = $input->getOption('save');
        $takeScreenshot = $input->getOption('screenshot');

        $io->title('Page Status Check');

        if ($url) {
            $io->section('Checking URL: ' . $url);
            $result = $this->checkUrl($url);
            if ($save) {
                $this->saveResult($result, 0, $takeScreenshot);
            }
        } elseif ($pageId) {
            $pageId = (int) $pageId;
            $io->section('Checking Page ID: ' . $pageId);
            $result = $this->checkPage($pageId);
            if ($save) {
                $this->saveResult($result, $pageId, $takeScreenshot);
            }
        } else {
            $io->section('Checking all pages...');
            $pages = $this->getAllPages();
            $successCount = 0;
            $failureCount = 0;

            foreach ($pages as $page) {
                $result = $this->checkPage($page['uid']);
                if ($save) {
                    $this->saveResult($result, $page['uid'], $takeScreenshot);
                }
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
                $io->write('.');
            }

            $io->newLine();
            $io->section('Summary');
            $io->table(
                ['Total', 'Online', 'Offline'],
                [[count($pages), $successCount, $failureCount]]
            );

            $io->success('Checked ' . count($pages) . ' pages');

            return Command::SUCCESS;
        }

        $this->displayResult($io, $result);

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    protected function checkPage(int $pageId): array
    {
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        $page = $pageRepository->getPage($pageId);

        if (!$page) {
            return [
                'success' => false,
                'message' => 'Page not found',
                'statusCode' => 404,
                'url' => '',
                'duration' => 0,
                'details' => [
                    'error' => 'The specified page ID does not exist',
                ],
            ];
        }

        $url = $this->buildPageUrl($page);

        return $this->checkUrl($url);
    }

    protected function checkUrl(string $url): array
    {
        $startTime = microtime(true);
        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => true,
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'track_redirects' => true,
            ],
            'headers' => [
                'User-Agent' => 'TYPO3 PageStatus/1.0',
            ],
        ]);

        $effectiveUrl = $url;
        $redirectCount = 0;
        $error = null;
        $httpCode = 0;

        try {
            $response = $client->request('HEAD', $url);
            $httpCode = $response->getStatusCode();
            $effectiveUrl = (string) $response->getHeaderLine('X-Guzzle-Redirect-History') ?: $url;
            $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
            $redirectCount = count($redirectHistory);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $httpCode = $response ? $response->getStatusCode() : 0;
            $error = $e->getMessage();
        }

        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);

        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'message' => $success ? 'Page is accessible' : 'Page returned error status',
            'statusCode' => $httpCode,
            'url' => $url,
            'duration' => $duration,
            'details' => [
                'redirectCount' => $redirectCount,
                'finalUrl' => $effectiveUrl,
                'error' => $error,
            ],
        ];
    }

    protected function saveResult(array $result, int $pageId, bool $takeScreenshot): void
    {
        $screenshotService = $this->screenshotService ?? GeneralUtility::makeInstance(ScreenshotService::class);
        $pageStatusRepository = $this->pageStatusRepository ?? GeneralUtility::makeInstance(PageStatusRepository::class);

        $screenshotPath = '';
        if ($takeScreenshot && $result['url']) {
            $screenshotPath = $screenshotService->takeScreenshot($result['url']);
        }

        $pageStatusData = [
            'page_id' => $pageId,
            'page_url' => $result['url'],
            'is_online' => $result['success'],
            'http_status_code' => $result['statusCode'],
            'last_check' => date('Y-m-d H:i:s'),
            'screenshot_path' => $screenshotPath,
            'error_message' => $result['details']['error'] ?? '',
        ];

        $pageStatusRepository->save($pageStatusData);
    }

    protected function buildPageUrl(array $page): string
    {
        $siteFactory = GeneralUtility::makeInstance(SiteFactory::class);
        try {
            $site = $siteFactory->getSiteByPageId($page['uid']);
            $base = $site->getBase();
            $language = $page['sys_language_uid'] ?? 0;

            return $base . 'index.php?id=' . $page['uid'] . ($language ? '&L=' . $language : '');
        } catch (Exception $e) {
            $host = GeneralUtility::getIndpEnv('HTTP_HOST') ?? 'localhost';
            $protocol = GeneralUtility::getIndpEnv('TYPO3_SSL') ? 'https' : 'http';

            return $protocol . '://' . $host . '/index.php?id=' . $page['uid'];
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

    protected function displayResult(SymfonyStyle $io, array $result): void
    {
        $io->section('Results');
        $io->table(
            ['Property', 'Value'],
            [
                ['Status', $result['success'] ? '<fg=green;options=bold>Success</>' : '<fg=red;options=bold>Failed</>'],
                ['Message', $result['message']],
                ['HTTP Status', $result['statusCode']],
                ['URL', $result['url']],
                ['Duration', $result['duration'] . ' ms'],
            ]
        );

        if ($result['details']['error']) {
            $io->warning('Guzzle Error: ' . $result['details']['error']);
        }

        if (!$result['success']) {
            $io->error('Page check failed. Please review the details above.');
        }
    }
}
