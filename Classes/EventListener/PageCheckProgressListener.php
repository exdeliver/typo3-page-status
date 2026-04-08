<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\EventListener;

use Exdeliver\PageStatus\Event\PageCheckProgressEvent;
use Exdeliver\PageStatus\Service\SseHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

#[AsEventListener(event: PageCheckProgressEvent::class, method: 'onPageCheckProgress')]
final readonly class PageCheckProgressListener
{
    public function __construct(
        private SseHandler $sseHandler,
        private LoggerInterface $logger
    ) {
    }

    public function onPageCheckProgress(PageCheckProgressEvent $event): void
    {
        try {
            $this->sseHandler->broadcast(
                PageCheckProgressEvent::EVENT_NAME,
                $event->toArray(),
                $event->getSessionId()
            );

            $this->logger->info('Page check progress broadcasted', [
                'pageId' => $event->getCurrentPageId(),
                'step' => $event->getCurrentStep(),
                'total' => $event->getTotalSteps(),
                'isComplete' => $event->isComplete(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to broadcast page check progress', [
                'error' => $e->getMessage(),
                'pageId' => $event->getCurrentPageId(),
            ]);
        }
    }
}
