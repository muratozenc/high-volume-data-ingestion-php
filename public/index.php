<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Predis\Client as RedisClient;
use Slim\Factory\AppFactory;
use Telemetry\Controller\TelemetryController;
use Telemetry\Database\Database;
use Telemetry\Repository\TelemetryRepository;
use Telemetry\Service\IngestionService;
use Telemetry\Service\RateLimiter;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$db = new Database(
    $_ENV['DB_HOST'] ?? 'mysql',
    (int) ($_ENV['DB_PORT'] ?? 3306),
    $_ENV['DB_NAME'] ?? 'telemetry',
    $_ENV['DB_USER'] ?? 'telemetry_user',
    $_ENV['DB_PASS'] ?? 'telemetry_pass'
);

$redis = new RedisClient([
    'host' => $_ENV['REDIS_HOST'] ?? 'redis',
    'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
]);

$telemetryRepository = new TelemetryRepository($db);
$ingestionService = new IngestionService($telemetryRepository, $db);
$rateLimiter = new RateLimiter($redis);
$telemetryController = new TelemetryController($ingestionService, $rateLimiter);

$app->post('/telemetry/batch', [$telemetryController, 'batchIngest']);

$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'ok']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

