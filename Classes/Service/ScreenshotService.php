<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Client\RequestClient;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ScreenshotService
{
    protected string $storagePath = 'fileadmin/page_status/screenshots/';

    public function takeScreenshot(string $url): string
    {
        $this->ensureStorageDirectoryExists();

        $filename = $this->generateFilename($url);
        $filePath = $this->storagePath . $filename;

        $physicalPath = Environment::getPublicPath() . '/' . $filePath;

        $screenshotData = $this->captureScreenshotWithCurl($url);

        if ($screenshotData !== null) {
            file_put_contents($physicalPath, $screenshotData);

            return $filePath;
        }

        return $this->createPlaceholderImage($url, $filePath);
    }

    protected function captureScreenshotWithCurl(string $url): ?string
    {
        return null;
    }

    protected function createPlaceholderImage(string $url, string $filePath): string
    {
        $physicalPath = Environment::getPublicPath() . '/' . $filePath;

        $urlTruncated = strlen($url) > 50 ? substr($url, 0, 50) . '...' : $url;
        $svgContent = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">
                <rect fill="#f0f0f0" width="800" height="600"/>
                <text x="400" y="280" font-family="Arial" font-size="14" fill="#666" text-anchor="middle">Screenshot</text>
                <text x="400" y="300" font-family="Arial" font-size="12" fill="#999" text-anchor="middle">%s</text>
                <text x="400" y="340" font-family="Arial" font-size="10" fill="#ccc" text-anchor="middle">Capture requires headless browser setup</text>
            </svg>',
            htmlspecialchars($urlTruncated, ENT_QUOTES, 'UTF-8')
        );

        file_put_contents($physicalPath, $svgContent);

        return $filePath;
    }

    protected function ensureStorageDirectoryExists(): void
    {
        $physicalPath = Environment::getPublicPath() . '/' . $this->storagePath;
        if (!is_dir($physicalPath)) {
            GeneralUtility::mkdir_deep($physicalPath);
        }
    }

    protected function generateFilename(string $url): string
    {
        $urlHash = md5($url . time() . mt_rand());

        return 'screenshot_' . $urlHash . '.svg';
    }

    public function getScreenshotUrl(string $filePath): string
    {
        return GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . $filePath;
    }

    public function deleteScreenshot(string $filePath): bool
    {
        $physicalPath = Environment::getPublicPath() . '/' . $filePath;
        if (file_exists($physicalPath)) {
            return unlink($physicalPath);
        }

        return false;
    }
}
