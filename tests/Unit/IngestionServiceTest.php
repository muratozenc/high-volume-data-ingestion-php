<?php

declare(strict_types=1);

namespace Telemetry\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Telemetry\Database\Database;
use Telemetry\Repository\TelemetryRepository;
use Telemetry\Service\IngestionService;

class IngestionServiceTest extends TestCase
{
    private Database $db;
    private TelemetryRepository $repository;
    private IngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->db = new Database('sqlite', 0, ':memory:', '', '');
        $pdo = $this->db->getPdo();
        
        $pdo->exec('CREATE TABLE telemetry_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id TEXT NOT NULL UNIQUE,
            device_id TEXT NOT NULL,
            ts TEXT NOT NULL,
            lat REAL NULL,
            lng REAL NULL,
            speed REAL NULL,
            extra TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');
        
        $pdo->exec('CREATE TABLE ingestion_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            batch_id TEXT NOT NULL,
            accepted INTEGER NOT NULL DEFAULT 0,
            duplicates INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(device_id, batch_id)
        )');
        
        $this->repository = new TelemetryRepository($this->db);
        $this->service = new IngestionService($this->repository, $this->db);
    }

    public function testIdempotencySameBatchReturnsSameResult(): void
    {
        $deviceId = 'dev-1';
        $batchId = 'batch-1';
        $events = [
            [
                'event_id' => 'evt-1',
                'ts' => '2024-01-01T10:00:00.000000',
                'lat' => 40.7128,
                'lng' => -74.0060,
                'speed' => 50.0,
                'extra' => [],
            ],
        ];

        $result1 = $this->service->processBatch($deviceId, $batchId, $events);
        $result2 = $this->service->processBatch($deviceId, $batchId, $events);

        $this->assertEquals($result1, $result2);
        $this->assertEquals(1, $result1['accepted']);
        $this->assertEquals(0, $result1['duplicates']);
        
        $stmt = $this->db->getPdo()->query('SELECT COUNT(*) as count FROM telemetry_events');
        $count = $stmt->fetch()['count'];
        $this->assertEquals(1, (int) $count);
    }

    public function testDeduplicationWithinBatch(): void
    {
        $deviceId = 'dev-1';
        $batchId = 'batch-1';
        $events = [
            [
                'event_id' => 'evt-1',
                'ts' => '2024-01-01T10:00:00.000000',
            ],
            [
                'event_id' => 'evt-1',
                'ts' => '2024-01-01T10:00:01.000000',
            ],
            [
                'event_id' => 'evt-2',
                'ts' => '2024-01-01T10:00:02.000000',
            ],
        ];

        $result = $this->service->processBatch($deviceId, $batchId, $events);

        $this->assertEquals(2, $result['accepted']);
        $this->assertEquals(1, $result['duplicates']);
    }

    public function testDeduplicationAcrossBatches(): void
    {
        $deviceId = 'dev-1';
        $events1 = [
            [
                'event_id' => 'evt-1',
                'ts' => '2024-01-01T10:00:00.000000',
            ],
        ];
        
        $events2 = [
            [
                'event_id' => 'evt-1',
                'ts' => '2024-01-01T10:00:01.000000',
            ],
            [
                'event_id' => 'evt-2',
                'ts' => '2024-01-01T10:00:02.000000',
            ],
        ];

        $result1 = $this->service->processBatch($deviceId, 'batch-1', $events1);
        $result2 = $this->service->processBatch($deviceId, 'batch-2', $events2);

        $this->assertEquals(1, $result1['accepted']);
        $this->assertEquals(0, $result1['duplicates']);
        
        $this->assertEquals(1, $result2['accepted']);
        $this->assertEquals(1, $result2['duplicates']);
        
        $stmt = $this->db->getPdo()->query('SELECT COUNT(*) as count FROM telemetry_events');
        $count = $stmt->fetch()['count'];
        $this->assertEquals(2, (int) $count);
    }
}

