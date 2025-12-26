<?php

declare(strict_types=1);

namespace Telemetry\Service;

use Telemetry\Database\Database;
use Telemetry\Repository\TelemetryRepository;

class IngestionService
{
    public function __construct(
        private TelemetryRepository $repository,
        private Database $db
    ) {
    }

    public function processBatch(string $deviceId, string $batchId, array $events): array
    {
        if ($this->repository->batchExists($deviceId, $batchId)) {
            $result = $this->repository->getBatchResult($deviceId, $batchId);
            if ($result !== null) {
                return [
                    'accepted' => (int) $result['accepted'],
                    'duplicates' => (int) $result['duplicates'],
                ];
            }
        }

        $eventIds = array_column($events, 'event_id');
        $existingEventIds = $this->repository->findExistingEventIds($eventIds);
        $existingSet = array_flip($existingEventIds);

        $seenInBatch = [];
        $newEvents = [];
        $duplicateCount = 0;

        foreach ($events as $event) {
            $eventId = $event['event_id'];
            
            if (isset($seenInBatch[$eventId])) {
                $duplicateCount++;
                continue;
            }
            
            if (isset($existingSet[$eventId])) {
                $duplicateCount++;
                $seenInBatch[$eventId] = true;
                continue;
            }

            $seenInBatch[$eventId] = true;

            $newEvents[] = [
                'event_id' => $event['event_id'],
                'device_id' => $deviceId,
                'ts' => $event['ts'],
                'lat' => $event['lat'] ?? null,
                'lng' => $event['lng'] ?? null,
                'speed' => $event['speed'] ?? null,
                'extra' => json_encode($event['extra'] ?? [], JSON_UNESCAPED_UNICODE),
            ];
        }

        $this->db->beginTransaction();
        try {
            if (!empty($newEvents)) {
                $this->repository->bulkInsertEvents($newEvents);
            }

            $accepted = count($newEvents);
            $this->repository->saveBatchResult($deviceId, $batchId, $accepted, $duplicateCount);

            $this->db->commit();

            return [
                'accepted' => $accepted,
                'duplicates' => $duplicateCount,
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}

