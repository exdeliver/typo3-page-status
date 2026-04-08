<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

final class SseHandler
{

    private static array $activeConnections = [];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function handleSseConnection(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = $request->getHeaderLine('X-Session-ID');
        if (!$sessionId) {
            $queryParams = $request->getQueryParams();
            $sessionId = $queryParams['session_id'] ?? null;
        }
        if (!$sessionId) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $sessionId = session_id();
        }

        $lastEventId = $request->getHeaderLine('Last-Event-ID');

        $response = new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $stream = new Stream('php://temp', 'w');
        $response = $response->withBody($stream);

        self::$activeConnections[$sessionId] = $stream;

        $this->sendEventToStream($stream, 'connected', json_encode(['sessionId' => $sessionId, 'lastEventId' => $lastEventId]));

        $this->maintainConnection($stream, $sessionId);

        return $response;
    }

    public function broadcast(string $eventName, array $data, ?string $targetSessionId = null): void
    {
        $data['timestamp'] = time();
        $jsonData = json_encode($data);

        foreach (self::$activeConnections as $sessionId => $stream) {
            if ($targetSessionId !== null && $sessionId !== $targetSessionId) {
                continue;
            }

            if ($this->sendEventToOutput($eventName, $jsonData)) {
            } else {
                $this->removeConnection($sessionId);
            }
        }
    }

    private function sendEventToStream($stream, string $eventName, string $data): bool
    {
        try {
            $event = "event: {$eventName}\n";
            $event .= "data: {$data}\n\n";
            $stream->write($event);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to send SSE event', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendEventToOutput(string $eventName, string $data): bool
    {
        try {
            $event = "event: {$eventName}\n";
            $event .= "data: {$data}\n\n";
            echo $event;
            if (function_exists('flush')) {
                @flush();
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to send SSE event to output', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function maintainConnection($stream, string $sessionId): void
    {
        set_time_limit(300);

        $pingInterval = 15;
        $lastPing = time();

        while (isset(self::$activeConnections[$sessionId])) {
            if ($this->isClientDisconnected($stream)) {
                $this->removeConnection($sessionId);
                break;
            }

            if (time() - $lastPing >= $pingInterval) {
                $this->sendEventToOutput('ping', json_encode(['time' => time()]));
                $lastPing = time();
            }

            usleep(100000);
        }
    }

    private function isClientDisconnected($stream): bool
    {
        if (!$stream->isWritable()) {
            return true;
        }

        if (connection_aborted()) {
            return true;
        }

        return false;
    }

    private function removeConnection(string $sessionId): void
    {
        if (isset(self::$activeConnections[$sessionId])) {
            try {
                @fclose(self::$activeConnections[$sessionId]);
            } catch (Throwable $e) {
            }
            unset(self::$activeConnections[$sessionId]);
        }
    }

    public function removeConnectionPublic(string $sessionId): void
    {
        $this->removeConnection($sessionId);
    }

    public static function getActiveConnectionCount(): int
    {
        return count(self::$activeConnections);
    }

    public static function closeAllConnections(): void
    {
        foreach (self::$activeConnections as $sessionId => $stream) {
            try {
                @fclose($stream);
            } catch (Throwable $e) {
            }
        }
        self::$activeConnections = [];
    }
}
