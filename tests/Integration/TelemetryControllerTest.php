<?php

declare(strict_types=1);

namespace Telemetry\Tests\Integration;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Slim\Factory\AppFactory;
use Telemetry\Controller\TelemetryController;
use Telemetry\Database\Database;
use Telemetry\Repository\TelemetryRepository;
use Telemetry\Service\IngestionService;
use Telemetry\Service\RateLimiter;

class TelemetryControllerTest extends TestCase
{
    private Database $db;
    private RedisClient $redis;
    private IngestionService $ingestionService;
    private RateLimiter $rateLimiter;
    private TelemetryController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
        $dotenv->load();
        
        $this->db = new Database(
            $_ENV['DB_HOST'] ?? 'mysql',
            (int) ($_ENV['DB_PORT'] ?? 3306),
            $_ENV['DB_NAME'] ?? 'telemetry',
            $_ENV['DB_USER'] ?? 'telemetry_user',
            $_ENV['DB_PASS'] ?? 'telemetry_pass'
        );
        
        $this->redis = new RedisClient([
            'host' => $_ENV['REDIS_HOST'] ?? 'redis',
            'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        ]);
        
        $this->redis->flushdb();
        
        $repository = new TelemetryRepository($this->db);
        $this->ingestionService = new IngestionService($repository, $this->db);
        $this->rateLimiter = new RateLimiter($this->redis);
        $this->controller = new TelemetryController($this->ingestionService, $this->rateLimiter);
        
        $this->runMigrations();
    }

    protected function tearDown(): void
    {
        $this->db->getPdo()->exec('TRUNCATE TABLE telemetry_events');
        $this->db->getPdo()->exec('TRUNCATE TABLE ingestion_batches');
        $this->redis->flushdb();
    }

    private function runMigrations(): void
    {
        $pdo = $this->db->getPdo();
        
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(255) PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');
        
        $migrations = [
            '002_create_telemetry_events' => 'CREATE TABLE IF NOT EXISTS telemetry_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id VARCHAR(255) NOT NULL UNIQUE,
                device_id VARCHAR(255) NOT NULL,
                ts DATETIME(6) NOT NULL,
                lat DECIMAL(10, 8) NULL,
                lng DECIMAL(11, 8) NULL,
                speed DECIMAL(8, 2) NULL,
                extra JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_device_ts (device_id, ts),
                INDEX idx_ts (ts)
            )',
            '003_create_ingestion_batches' => 'CREATE TABLE IF NOT EXISTS ingestion_batches (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(255) NOT NULL,
                batch_id VARCHAR(255) NOT NULL,
                accepted INT UNSIGNED NOT NULL DEFAULT 0,
                duplicates INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_device_batch (device_id, batch_id),
                INDEX idx_device_created (device_id, created_at)
            )',
        ];
        
        foreach ($migrations as $version => $sql) {
            $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
            $stmt->execute([$version]);
            if (!$stmt->fetch()) {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
                $stmt->execute([$version]);
            }
        }
    }

    public function testSameBatchPostedTwiceReturnsSameResult(): void
    {
        $deviceId = 'dev-1';
        $batchId = 'batch-' . uniqid();
        $events = [
            [
                'event_id' => 'evt-1',
                'ts' => '2024-01-01T10:00:00.000000',
                'lat' => 40.7128,
                'lng' => -74.0060,
                'speed' => 50.0,
            ],
            [
                'event_id' => 'evt-2',
                'ts' => '2024-01-01T10:00:01.000000',
                'lat' => 40.7129,
                'lng' => -74.0061,
                'speed' => 51.0,
            ],
        ];

        $request1 = $this->createMockRequest([
            'device_id' => $deviceId,
            'batch_id' => $batchId,
            'events' => $events,
        ]);
        $response1 = $this->createMockResponse();
        
        $result1 = $this->controller->batchIngest($request1, $response1);
        $body1 = json_decode((string) $result1->getBody(), true);

        $request2 = $this->createMockRequest([
            'device_id' => $deviceId,
            'batch_id' => $batchId,
            'events' => $events,
        ]);
        $response2 = $this->createMockResponse();
        
        $result2 = $this->controller->batchIngest($request2, $response2);
        $body2 = json_decode((string) $result2->getBody(), true);

        $this->assertEquals($body1, $body2);
        $this->assertEquals(2, $body1['accepted']);
        $this->assertEquals(0, $body1['duplicates']);
        
        $stmt = $this->db->getPdo()->query('SELECT COUNT(*) as count FROM telemetry_events');
        $count = $stmt->fetch()['count'];
        $this->assertEquals(2, (int) $count);
    }

    public function testDuplicatesInsideBatchAreHandled(): void
    {
        $deviceId = 'dev-1';
        $batchId = 'batch-' . uniqid();
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
            [
                'event_id' => 'evt-2',
                'ts' => '2024-01-01T10:00:03.000000',
            ],
        ];

        $request = $this->createMockRequest([
            'device_id' => $deviceId,
            'batch_id' => $batchId,
            'events' => $events,
        ]);
        $response = $this->createMockResponse();
        
        $result = $this->controller->batchIngest($request, $response);
        $body = json_decode((string) $result->getBody(), true);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals(2, $body['accepted']);
        $this->assertEquals(2, $body['duplicates']);
        
        $stmt = $this->db->getPdo()->query('SELECT COUNT(*) as count FROM telemetry_events');
        $count = $stmt->fetch()['count'];
        $this->assertEquals(2, (int) $count);
    }

    private function createMockRequest(array $body): object
    {
        return new class($body) {
            private array $body;
            
            public function __construct(array $body)
            {
                $this->body = $body;
            }
            
            public function getParsedBody(): array
            {
                return $this->body;
            }
        };
    }

    private function createMockResponse(): object
    {
        return new class {
            private string $bodyContent = '';
            private int $statusCode = 200;
            private array $headers = [];
            
            public function getBody(): \Psr\Http\Message\StreamInterface
            {
                $self = $this;
                return new class($self) implements \Psr\Http\Message\StreamInterface {
                    private object $response;
                    private string $content = '';
                    
                    public function __construct(object $response)
                    {
                        $this->response = $response;
                    }
                    
                    public function write(string $content): int
                    {
                        $this->content = $content;
                        $this->response->bodyContent = $content;
                        return strlen($content);
                    }
                    
                    public function __toString(): string
                    {
                        return $this->content ?: $this->response->bodyContent;
                    }
                    
                    public function __get($name) { return null; }
                    public function __set($name, $value) {}
                    public function close() {}
                    public function detach() { return null; }
                    public function getSize() { return null; }
                    public function tell() { return 0; }
                    public function eof() { return true; }
                    public function isSeekable() { return false; }
                    public function seek($offset, $whence = SEEK_SET) {}
                    public function rewind() {}
                    public function isWritable() { return true; }
                    public function read($length) { return ''; }
                    public function getContents() { return $this->__toString(); }
                    public function getMetadata($key = null) { return null; }
                };
            }
            
            public function withStatus(int $code, string $reasonPhrase = ''): self
            {
                $this->statusCode = $code;
                return $this;
            }
            
            public function getStatusCode(): int
            {
                return $this->statusCode;
            }
            
            public function withHeader(string $name, $value): self
            {
                $this->headers[$name] = $value;
                return $this;
            }
            
            public function getHeader($name) { return $this->headers[$name] ?? []; }
            public function getHeaderLine($name) { return implode(', ', $this->getHeader($name)); }
            public function hasHeader($name) { return isset($this->headers[$name]); }
            public function withoutHeader($name) { unset($this->headers[$name]); return $this; }
            public function getHeaders() { return $this->headers; }
            public function getProtocolVersion() { return '1.1'; }
            public function withProtocolVersion($version) { return $this; }
            public function withBody(\Psr\Http\Message\StreamInterface $body) { return $this; }
        };
    }
}

