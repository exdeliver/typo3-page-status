<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Event;

final class PageCheckProgressEvent
{
    public const EVENT_NAME = 'pageCheckProgress';

    public function __construct(
        private readonly int $currentPageId,
        private readonly string $currentPageTitle,
        private readonly string $pageUrl,
        private readonly int $currentStep,
        private readonly int $totalSteps,
        private readonly bool $isComplete,
        private readonly ?array $checkResult = null,
        private readonly ?string $sessionId = null
    ) {
    }

    public function getCurrentPageId(): int
    {
        return $this->currentPageId;
    }

    public function getCurrentPageTitle(): string
    {
        return $this->currentPageTitle;
    }

    public function getPageUrl(): string
    {
        return $this->pageUrl;
    }

    public function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    public function getTotalSteps(): int
    {
        return $this->totalSteps;
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function getCheckResult(): ?array
    {
        return $this->checkResult;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function toArray(): array
    {
        return [
            'currentPageId' => $this->currentPageId,
            'currentPageTitle' => $this->currentPageTitle,
            'pageUrl' => $this->pageUrl,
            'currentStep' => $this->currentStep,
            'totalSteps' => $this->totalSteps,
            'isComplete' => $this->isComplete,
            'checkResult' => $this->checkResult,
            'sessionId' => $this->sessionId,
            'timestamp' => time(),
        ];
    }
}
