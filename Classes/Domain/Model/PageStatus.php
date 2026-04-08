<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Domain\Model;

use DateTime;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class PageStatus extends AbstractEntity
{
    protected int $pageId = 0;
    protected string $pageUrl = '';
    protected bool $isOnline = true;
    protected int $httpStatusCode = 0;
    protected ?DateTime $lastCheck = null;
    protected string $screenshotPath = '';
    protected string $errorMessage = '';

    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function setPageId(int $pageId): void
    {
        $this->pageId = $pageId;
    }

    public function getPageUrl(): string
    {
        return $this->pageUrl;
    }

    public function setPageUrl(string $pageUrl): void
    {
        $this->pageUrl = $pageUrl;
    }

    public function isOnline(): bool
    {
        return $this->isOnline;
    }

    public function setIsOnline(bool $isOnline): void
    {
        $this->isOnline = $isOnline;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode(int $httpStatusCode): void
    {
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getLastCheck(): ?DateTime
    {
        return $this->lastCheck;
    }

    public function setLastCheck(?DateTime $lastCheck): void
    {
        $this->lastCheck = $lastCheck;
    }

    public function getScreenshotPath(): string
    {
        return $this->screenshotPath;
    }

    public function setScreenshotPath(string $screenshotPath): void
    {
        $this->screenshotPath = $screenshotPath;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }
}
