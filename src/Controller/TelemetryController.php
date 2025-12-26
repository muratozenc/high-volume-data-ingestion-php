<?php

declare(strict_types=1);

namespace Telemetry\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telemetry\Service\IngestionService;
use Telemetry\Service\RateLimiter;

class TelemetryController
{
    public function __construct(
        private IngestionService $ingestionService,
        private RateLimiter $rateLimiter
    ) {
    }

    public function batchIngest(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();

        if (!isset($body['device_id']) || !isset($body['batch_id']) || !isset($body['events'])) {
            $response->getBody()->write(json_encode([
                'error' => 'Missing required fields: device_id, batch_id, events',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $deviceId = (string) $body['device_id'];
        $batchId = (string) $body['batch_id'];
        $events = $body['events'];

        if (!is_array($events)) {
            $response->getBody()->write(json_encode([
                'error' => 'events must be an array',
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!$this->rateLimiter->isAllowed($deviceId, count($events))) {
            $response->getBody()->write(json_encode([
                'error' => 'Rate limit exceeded',
            ]));
            return $response->withStatus(429)->withHeader('Content-Type', 'application/json');
        }

        foreach ($events as $event) {
            if (!isset($event['event_id']) || !isset($event['ts'])) {
                $response->getBody()->write(json_encode([
                    'error' => 'Each event must have event_id and ts fields',
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }

        try {
            $result = $this->ingestionService->processBatch($deviceId, $batchId, $events);

            $response->getBody()->write(json_encode($result));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}

