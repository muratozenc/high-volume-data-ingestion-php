<?php

declare(strict_types=1);

namespace Telemetry\Service;

use Predis\Client;

class RateLimiter
{
    private const TOKEN_BUCKET_PREFIX = 'rate_limit:';
    private const DEFAULT_CAPACITY = 100;
    private const DEFAULT_REFILL_RATE = 10;

    public function __construct(
        private Client $redis,
        private int $capacity = self::DEFAULT_CAPACITY,
        private int $refillRate = self::DEFAULT_REFILL_RATE
    ) {
    }

    public function isAllowed(string $deviceId, int $tokens = 1): bool
    {
        $key = self::TOKEN_BUCKET_PREFIX . $deviceId;
        $now = microtime(true);

        $lua = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local tokens_needed = tonumber(ARGV[3])
local now = tonumber(ARGV[4])

local bucket = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(bucket[1]) or capacity
local last_refill = tonumber(bucket[2]) or now

local elapsed = now - last_refill
local tokens_to_add = math.floor(elapsed * refill_rate)
tokens = math.min(capacity, tokens + tokens_to_add)

if tokens >= tokens_needed then
    tokens = tokens - tokens_needed
    redis.call('HMSET', key, 'tokens', tokens, 'last_refill', now)
    redis.call('EXPIRE', key, 3600)
    return 1
else
    redis.call('HMSET', key, 'tokens', tokens, 'last_refill', now)
    redis.call('EXPIRE', key, 3600)
    return 0
end
LUA;

        $result = $this->redis->eval($lua, 1, $key, $this->capacity, $this->refillRate, $tokens, $now);
        return (bool) $result;
    }
}

