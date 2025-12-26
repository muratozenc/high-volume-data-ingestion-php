<?php

declare(strict_types=1);

namespace Telemetry\Repository;

use PDO;
use Telemetry\Database\Database;

class TelemetryRepository
{
    public function __construct(
        private Database $db
    ) {
    }

    public function batchExists(string $deviceId, string $batchId): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT 1 FROM ingestion_batches WHERE device_id = ? AND batch_id = ? LIMIT 1'
        );
        $stmt->execute([$deviceId, $batchId]);
        return (bool) $stmt->fetch();
    }

    public function getBatchResult(string $deviceId, string $batchId): ?array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT accepted, duplicates FROM ingestion_batches 
             WHERE device_id = ? AND batch_id = ?'
        );
        $stmt->execute([$deviceId, $batchId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findExistingEventIds(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->db->getPdo()->prepare(
            "SELECT event_id FROM telemetry_events WHERE event_id IN ($placeholders)"
        );
        $stmt->execute($eventIds);
        return array_column($stmt->fetchAll(), 'event_id');
    }

    public function bulkInsertEvents(array $events): void
    {
        if (empty($events)) {
            return;
        }

        $values = [];
        $params = [];
        foreach ($events as $event) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?)';
            $params[] = $event['event_id'];
            $params[] = $event['device_id'];
            $params[] = $event['ts'];
            $params[] = $event['lat'];
            $params[] = $event['lng'];
            $params[] = $event['speed'];
            $params[] = $event['extra'];
        }

        $sql = 'INSERT INTO telemetry_events 
                (event_id, device_id, ts, lat, lng, speed, extra) 
                VALUES ' . implode(', ', $values);

        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute($params);
    }

    public function saveBatchResult(
        string $deviceId,
        string $batchId,
        int $accepted,
        int $duplicates
    ): void {
        $stmt = $this->db->getPdo()->prepare(
            'INSERT INTO ingestion_batches (device_id, batch_id, accepted, duplicates, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
                accepted = VALUES(accepted),
                duplicates = VALUES(duplicates)'
        );
        $stmt->execute([$deviceId, $batchId, $accepted, $duplicates]);
    }
}

