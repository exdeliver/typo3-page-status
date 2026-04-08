<?php

declare(strict_types=1);

namespace Exdeliver\PageStatus\Controller;

use Exdeliver\PageStatus\Service\SseHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

final readonly class SseController
{
    public function __construct(
        private SseHandler $sseHandler
    ) {
    }

    public function connectAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $sessionId = $queryParams['session_id'] ?? null;

        if (!$sessionId) {
            return $this->createErrorResponse('No session ID provided');
        }

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');

        $response = new Response(200, [
            'Content-Type' => 'text/event-stream',
        ]);

        register_shutdown_function(function () use ($sessionId) {
            $this->sseHandler->removeConnectionPublic($sessionId);
        });

        $this->sendSseEvent('connected', json_encode(['sessionId' => $sessionId]));

        $this->maintainConnection($sessionId);

        return $response;
    }

    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Response();
        $response = $response->withHeader('Content-Type', 'application/json');

        $body = json_encode([
            'activeConnections' => SseHandler::getActiveConnectionCount(),
        ]);

        $stream = new Stream('php://temp', 'w');
        $stream->write($body);
        $stream->rewind();

        return $response->withBody($stream);
    }

    private function createErrorResponse(string $message): ResponseInterface
    {
        $response = new Response(500);
        $response = $response->withHeader('Content-Type', 'application/json');
        $stream = new Stream('php://temp', 'w');
        $stream->write(json_encode(['error' => $message]));
        $stream->rewind();

        return $response->withBody($stream);
    }

    private function sendSseEvent(string $eventName, string $data): void
    {
        $event = "event: {$eventName}\n";
        $event .= "data: {$data}\n\n";
        echo $event;
        if (function_exists('flush')) {
            @flush();
        }
    }

    private function maintainConnection(string $sessionId): void
    {
        set_time_limit(300);

        $pingInterval = 15;
        $lastPing = time();

        while (true) {
            if (connection_aborted() || !connection_status()) {
                break;
            }

            if (time() - $lastPing >= $pingInterval) {
                $this->sendSseEvent('ping', json_encode(['time' => time()]));
                $lastPing = time();
            }

            usleep(100000);
        }
    }
}
