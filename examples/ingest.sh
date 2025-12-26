#!/bin/bash

# Example script to ingest telemetry data

API_URL="${API_URL:-http://localhost:8080}"

# Example batch ingestion
curl -X POST "${API_URL}/telemetry/batch" \
  -H "Content-Type: application/json" \
  -d '{
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
  }'

echo ""

