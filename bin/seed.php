#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Telemetry\Database\Database;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$db = new Database(
    $_ENV['DB_HOST'] ?? 'mysql',
    (int) ($_ENV['DB_PORT'] ?? 3306),
    $_ENV['DB_NAME'] ?? 'telemetry',
    $_ENV['DB_USER'] ?? 'telemetry_user',
    $_ENV['DB_PASS'] ?? 'telemetry_pass'
);

$pdo = $db->getPdo();

$devices = ['dev-1', 'dev-2', 'dev-3'];
$events = [];

echo "Generating sample telemetry events...\n";

for ($i = 0; $i < 1000; $i++) {
    $deviceId = $devices[array_rand($devices)];
    $timestamp = date('Y-m-d H:i:s', time() - rand(0, 86400 * 7));
    $microseconds = str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $ts = $timestamp . '.' . $microseconds;
    
    $events[] = [
        'event_id' => 'evt-' . uniqid('', true),
        'device_id' => $deviceId,
        'ts' => $ts,
        'lat' => (float) (40.7128 + (rand(-100, 100) / 1000)),
        'lng' => (float) (-74.0060 + (rand(-100, 100) / 1000)),
        'speed' => (float) rand(0, 120),
        'extra' => json_encode(['sensor' => 'gps', 'accuracy' => rand(1, 10)]),
    ];
}

echo "Inserting " . count($events) . " events...\n";

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

$chunkSize = 100;
$chunks = array_chunk($events, $chunkSize);

foreach ($chunks as $chunk) {
    $chunkValues = [];
    $chunkParams = [];
    foreach ($chunk as $event) {
        $chunkValues[] = '(?, ?, ?, ?, ?, ?, ?)';
        $chunkParams[] = $event['event_id'];
        $chunkParams[] = $event['device_id'];
        $chunkParams[] = $event['ts'];
        $chunkParams[] = $event['lat'];
        $chunkParams[] = $event['lng'];
        $chunkParams[] = $event['speed'];
        $chunkParams[] = $event['extra'];
    }
    
    $sql = 'INSERT INTO telemetry_events 
            (event_id, device_id, ts, lat, lng, speed, extra) 
            VALUES ' . implode(', ', $chunkValues);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($chunkParams);
}

echo "✓ Seeded " . count($events) . " events\n";

$stmt = $pdo->query('SELECT device_id, COUNT(*) as count FROM telemetry_events GROUP BY device_id');
$summary = $stmt->fetchAll();

echo "\nSummary:\n";
foreach ($summary as $row) {
    echo "  {$row['device_id']}: {$row['count']} events\n";
}

