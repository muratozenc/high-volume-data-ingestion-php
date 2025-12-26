CREATE TABLE ingestion_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(255) NOT NULL,
    batch_id VARCHAR(255) NOT NULL,
    accepted INT UNSIGNED NOT NULL DEFAULT 0,
    duplicates INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_device_batch (device_id, batch_id),
    INDEX idx_device_created (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

