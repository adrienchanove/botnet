<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$configPath = getenv('MASTER_CONFIG') ?: __DIR__ . '/config.ini';
if (!is_file($configPath)) {
    $fallback = __DIR__ . '/config.sample.ini';
    $configPath = is_file($fallback) ? $fallback : $configPath;
}

$config = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
if ($config === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid configuration file']);
    exit;
}

$db = $config['db'] ?? [];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'] ?? '127.0.0.1',
    (int) ($db['port'] ?? 3306),
    $db['name'] ?? 'botnet',
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO(
        $dsn,
        (string) ($db['user'] ?? 'root'),
        (string) ($db['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'details' => $exception->getMessage()]);
    exit;
}

$pdo->exec('CREATE TABLE IF NOT EXISTS workers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT "pending",
    delay_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    worker_id BIGINT UNSIGNED NULL,
    html LONGTEXT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_jobs_status_id (status, id),
    INDEX idx_jobs_worker_id (worker_id),
    CONSTRAINT fk_jobs_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    if ($method === 'GET' && $path === '/health') {
        respond(200, ['status' => 'ok']);
    }

    if ($method === 'POST' && $path === '/workers/register') {
        $payload = readJson();
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            respond(422, ['error' => 'Worker name is required']);
        }

        $stmt = $pdo->prepare('INSERT INTO workers (name, last_seen_at) VALUES (:name, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_seen_at = UTC_TIMESTAMP()');
        $stmt->execute(['name' => $name]);

        $stmt = $pdo->prepare('SELECT id, name, last_seen_at FROM workers WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $worker = $stmt->fetch();

        respond(200, ['worker' => $worker]);
    }

    if ($method === 'POST' && $path === '/jobs/enqueue') {
        $payload = readJson();
        $url = trim((string) ($payload['url'] ?? ''));
        $delaySeconds = max(0, (int) ($payload['delay_seconds'] ?? 0));

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            respond(422, ['error' => 'A valid URL is required']);
        }

        $stmt = $pdo->prepare('INSERT INTO jobs (url, status, delay_seconds) VALUES (:url, "pending", :delay_seconds)');
        $stmt->execute([
            'url' => $url,
            'delay_seconds' => $delaySeconds,
        ]);

        $jobId = (int) $pdo->lastInsertId();
        respond(201, ['job' => ['id' => $jobId, 'url' => $url, 'status' => 'pending', 'delay_seconds' => $delaySeconds]]);
    }

    if ($method === 'GET' && $path === '/jobs/next') {
        $workerId = (int) ($_GET['worker_id'] ?? 0);
        if ($workerId <= 0) {
            respond(422, ['error' => 'worker_id is required']);
        }

        $stmt = $pdo->prepare('UPDATE workers SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id');
        $stmt->execute(['id' => $workerId]);
        if ($stmt->rowCount() === 0) {
            respond(404, ['error' => 'Worker not found']);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->query('SELECT id, url, delay_seconds FROM jobs WHERE status = "pending" ORDER BY id ASC LIMIT 1 FOR UPDATE');
        $job = $stmt->fetch();

        if ($job === false) {
            $pdo->commit();
            respond(200, ['job' => null]);
        }

        $update = $pdo->prepare('UPDATE jobs SET status = "in_progress", worker_id = :worker_id, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute([
            'worker_id' => $workerId,
            'id' => (int) $job['id'],
        ]);

        $pdo->commit();
        $job['id'] = (int) $job['id'];
        $job['delay_seconds'] = (int) $job['delay_seconds'];

        respond(200, ['job' => $job]);
    }

    if ($method === 'POST' && $path === '/jobs/result') {
        $payload = readJson();
        $workerId = (int) ($payload['worker_id'] ?? 0);
        $jobId = (int) ($payload['job_id'] ?? 0);
        $status = (string) ($payload['status'] ?? '');
        $html = isset($payload['html']) ? (string) $payload['html'] : null;
        $error = isset($payload['error']) ? (string) $payload['error'] : null;

        if ($workerId <= 0 || $jobId <= 0) {
            respond(422, ['error' => 'worker_id and job_id are required']);
        }

        if (!in_array($status, ['done', 'failed'], true)) {
            respond(422, ['error' => 'status must be done or failed']);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare('UPDATE jobs
            SET status = :status, html = :html, error_message = :error, updated_at = UTC_TIMESTAMP()
            WHERE id = :job_id AND worker_id = :worker_id AND status = "in_progress"');

        $stmt->execute([
            'status' => $status,
            'html' => $html,
            'error' => $error,
            'job_id' => $jobId,
            'worker_id' => $workerId,
        ]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            respond(404, ['error' => 'Active job assignment not found']);
        }

        $heartbeat = $pdo->prepare('UPDATE workers SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id');
        $heartbeat->execute(['id' => $workerId]);

        $pdo->commit();
        respond(200, ['job' => ['id' => $jobId, 'status' => $status]]);
    }

    respond(404, ['error' => 'Route not found']);
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, ['error' => 'Unhandled server error', 'details' => $throwable->getMessage()]);
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(400, ['error' => 'Invalid JSON body']);
    }

    return $decoded;
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
