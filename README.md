# High-Volume Data Ingestion API

A PHP 8.3 application demonstrating high-volume telemetry data ingestion patterns with idempotency, deduplication, bulk inserts, and rate limiting.

## Features

- **Idempotent Batch Ingestion**: Same `(device_id, batch_id)` returns identical results without creating duplicates
- **Global Deduplication**: `event_id` is unique globally across all batches
- **Bulk Insert**: Efficient batch processing using single INSERT statements
- **Rate Limiting**: Token bucket algorithm per device using Redis
- **Retry-Safe**: Safe to retry failed requests without side effects

## Architecture

### Database Schema

#### `telemetry_events`
Stores individual telemetry events with unique `event_id` constraint.

**Key Indexes:**
- `UNIQUE(event_id)` - Primary deduplication key
- `INDEX(device_id, ts)` - Composite index for device-specific time-range queries
- `INDEX(ts)` - Time-based queries

#### `ingestion_batches`
Tracks batch ingestion results for idempotency.

**Key Indexes:**
- `UNIQUE(device_id, batch_id)` - Idempotency key
- `INDEX(device_id, created_at)` - Device batch history queries

### Performance Decisions

#### 1. Bulk Insert Strategy
Instead of inserting events one-by-one, we use a single `INSERT ... VALUES (...), (...), ...` statement. This reduces:
- Network round-trips to database
- Transaction overhead
- Lock contention

**Example:**
```sql
INSERT INTO telemetry_events (event_id, device_id, ts, lat, lng, speed, extra)
VALUES 
  ('evt-1', 'dev-1', '2024-01-01 10:00:00.000000', 40.7128, -74.0060, 50.0, '{}'),
  ('evt-2', 'dev-1', '2024-01-01 10:00:01.000000', 40.7129, -74.0061, 51.0, '{}');
```

#### 2. Deduplication Strategy
- **Pre-check**: Before inserting, query existing `event_id`s using `IN (...)` clause
- **In-batch deduplication**: Track seen `event_id`s within the batch to handle duplicates
- **Database constraint**: `UNIQUE(event_id)` as final safeguard

This two-phase approach minimizes database writes for duplicates.

#### 3. Idempotency Implementation
- Check `ingestion_batches` table first using `(device_id, batch_id)` unique key
- If batch exists, return stored result without processing
- If new, process and store result atomically in transaction

#### 4. Index Strategy

**Recommended Indexes:**

```sql
-- Primary deduplication
UNIQUE KEY (event_id)

-- Device-specific queries (most common)
INDEX idx_device_ts (device_id, ts)

-- Time-range queries
INDEX idx_ts (ts)

-- Idempotency check
UNIQUE KEY uk_device_batch (device_id, batch_id)
```

**EXPLAIN Example:**

```sql
EXPLAIN SELECT * FROM telemetry_events 
WHERE device_id = 'dev-1' 
  AND ts BETWEEN '2024-01-01 00:00:00' AND '2024-01-01 23:59:59'
ORDER BY ts;
```

Expected output:
```
+----+-------------+------------------+------------+-------+---------------+-------------+---------+------+------+----------+-----------------------+
| id | select_type | table            | partitions | type  | possible_keys | key         | key_len | ref  | rows | filtered | Extra                 |
+----+-------------+------------------+------------+-------+---------------+-------------+---------+------+------+----------+-----------------------+
|  1 | SIMPLE      | telemetry_events | NULL       | range | idx_device_ts | idx_device_ts| 271     | NULL | 1000 |   100.00 | Using index condition |
+----+-------------+------------------+------------+-------+---------------+-------------+---------+------+------+----------+-----------------------+
```

The `idx_device_ts` composite index allows MySQL to:
1. Filter by `device_id` (leftmost prefix)
2. Filter by `ts` range (second column)
3. Avoid sorting (index is already ordered)

#### 5. Rate Limiting
Token bucket algorithm implemented in Redis using Lua script for atomicity:
- **Capacity**: 100 tokens per device
- **Refill Rate**: 10 tokens/second
- **Atomic Operations**: Lua script ensures thread-safe token management

## Setup

### Prerequisites
- Docker and Docker Compose
- PHP 8.3+ (for local development)

### Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd high-volume-data-ingestion-php
```

2. Install dependencies:
```bash
composer install
```

3. Copy environment file:
```bash
cp .env.example .env
```

4. Start services:
```bash
docker-compose up -d
```

5. Run migrations:
```bash
docker-compose exec php php bin/migrate.php
```

6. (Optional) Seed sample data:
```bash
docker-compose exec php php bin/seed.php
```

7. Start the development server:
```bash
# Using PHP built-in server
docker-compose exec php php -S 0.0.0.0:8080 -t public public/index.php

# Or use a web server like nginx/Apache pointing to public/index.php
```

The API will be available at `http://localhost:8080`

## API Usage

### POST /telemetry/batch

Ingest a batch of telemetry events.

**Request:**
```json
{
  "device_id": "dev-1",
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "events": [
    {
      "event_id": "evt-1",
      "ts": "2024-01-01T10:00:00.000000",
      "lat": 40.7128,
      "lng": -74.0060,
      "speed": 50.0,
      "extra": {
        "sensor": "gps",
        "accuracy": 5
      }
    },
    {
      "event_id": "evt-2",
      "ts": "2024-01-01T10:00:01.000000",
      "lat": 40.7129,
      "lng": -74.0061,
      "speed": 51.0,
      "extra": {}
    }
  ]
}
```

**Response (200 OK):**
```json
{
  "accepted": 2,
  "duplicates": 0
}
```

**Response (429 Rate Limited):**
```json
{
  "error": "Rate limit exceeded"
}
```

### Idempotency Example

Posting the same batch twice returns identical results:

```bash
# First request
curl -X POST http://localhost:8080/telemetry/batch \
  -H "Content-Type: application/json" \
  -d '{"device_id":"dev-1","batch_id":"batch-123","events":[...]}'
# Response: {"accepted":2,"duplicates":0}

# Second request (same batch_id)
curl -X POST http://localhost:8080/telemetry/batch \
  -H "Content-Type: application/json" \
  -d '{"device_id":"dev-1","batch_id":"batch-123","events":[...]}'
# Response: {"accepted":2,"duplicates":0} (same result, no new rows)
```

## Testing

### Run Tests

```bash
# Unit tests
composer test

# Code style check
composer cs-check

# Static analysis
composer stan
```

### Test Coverage

- **Unit Tests**: Idempotency logic, deduplication within batches
- **Integration Tests**: 
  - Same batch posted twice returns identical results
  - Duplicates within batch are handled correctly
  - Global deduplication across batches

## Development

### Code Quality Tools

- **PHPUnit**: Testing framework
- **PHPStan**: Static analysis (level 8)
- **PHP-CS-Fixer**: Code style (PSR-12)

### Running Locally

The application uses Slim 4 framework. 

**Using Docker (recommended):**
```bash
docker-compose exec php php -S 0.0.0.0:8080 -t public public/index.php
```

**Using Make commands:**
```bash
make up        # Start containers
make migrate   # Run migrations
make seed      # Seed data (optional)
make test      # Run tests
```

**Example request:**
```bash
./examples/ingest.sh
```

Or use a proper web server (nginx/Apache) configured to point to `public/index.php`.

## Performance Considerations

### Batch Size
- Recommended: 100-1000 events per batch
- Larger batches reduce HTTP overhead but increase memory usage
- Database has limits on query size (adjust chunking if needed)

### Database Connection Pooling
For production, consider:
- Connection pooling (e.g., ProxySQL)
- Read replicas for queries
- Partitioning by `device_id` or time ranges for very large datasets

### Redis Configuration
- Token bucket parameters are configurable in `RateLimiter`
- Adjust `capacity` and `refillRate` based on expected load
- Consider Redis cluster for high availability

## License

MIT

