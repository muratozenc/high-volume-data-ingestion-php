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

$migrationsDir = __DIR__ . '/../migrations';
$pdo = $db->getPdo();

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$stmt = $pdo->query('SELECT version FROM schema_migrations');
$applied = array_column($stmt->fetchAll(), 'version');

$files = glob($migrationsDir . '/*.sql');
usort($files, function ($a, $b) {
    return basename($a) <=> basename($b);
});

$appliedCount = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    
    if (in_array($version, $applied, true)) {
        echo "Skipping $version (already applied)\n";
        continue;
    }

    echo "Applying $version...\n";
    $sql = file_get_contents($file);
    
    try {
        $pdo->exec($sql);
        
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $stmt->execute([$version]);
        
        echo "✓ Applied $version\n";
        $appliedCount++;
    } catch (PDOException $e) {
        echo "✗ Error applying $version: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($appliedCount === 0) {
    echo "No new migrations to apply.\n";
} else {
    echo "\nApplied $appliedCount migration(s).\n";
}

