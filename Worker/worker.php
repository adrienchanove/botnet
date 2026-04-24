<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run from CLI.\n");
    exit(1);
}

$configPath = getenv('WORKER_CONFIG') ?: __DIR__ . '/config.ini';
if (!is_file($configPath)) {
    $fallback = __DIR__ . '/config.sample.ini';
    $configPath = is_file($fallback) ? $fallback : $configPath;
}

$config = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
if ($config === false) {
    fwrite(STDERR, "Invalid configuration file.\n");
    exit(1);
}

$master = $config['master'] ?? [];
$workerConfig = $config['worker'] ?? [];

$baseUrl = rtrim((string) ($master['base_url'] ?? 'http://127.0.0.1:8000'), '/');
$requestTimeout = max(1, (int) ($master['timeout_seconds'] ?? 20));
$workerName = trim((string) ($workerConfig['name'] ?? gethostname()));
$idlePoll = max(1, (int) ($workerConfig['idle_poll_seconds'] ?? 5));
$maxLoops = max(0, (int) ($workerConfig['max_loops'] ?? 0));

if ($workerName === '') {
    fwrite(STDERR, "Worker name cannot be empty.\n");
    exit(1);
}

$worker = apiRequest('POST', $baseUrl . '/workers/register', ['name' => $workerName], $requestTimeout);
$workerId = (int) ($worker['worker']['id'] ?? 0);

if ($workerId <= 0) {
    fwrite(STDERR, "Worker registration failed.\n");
    exit(1);
}

$iterations = 0;

while (true) {
    $iterations++;

    $response = apiRequest('GET', $baseUrl . '/jobs/next?worker_id=' . $workerId, null, $requestTimeout);
    $job = $response['job'] ?? null;

    if (!is_array($job)) {
        sleep($idlePoll);
        if ($maxLoops > 0 && $iterations >= $maxLoops) {
            break;
        }
        continue;
    }

    $jobId = (int) ($job['id'] ?? 0);
    $url = (string) ($job['url'] ?? '');
    $delaySeconds = max(0, (int) ($job['delay_seconds'] ?? 0));

    $result = crawl($url, $requestTimeout);

    apiRequest('POST', $baseUrl . '/jobs/result', [
        'worker_id' => $workerId,
        'job_id' => $jobId,
        'status' => $result['status'],
        'html' => $result['html'],
        'error' => $result['error'],
    ], $requestTimeout);

    sleep($delaySeconds);

    if ($maxLoops > 0 && $iterations >= $maxLoops) {
        break;
    }
}

function crawl(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'botnet-worker/1.0',
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => 'failed', 'html' => null, 'error' => $error === '' ? 'Unknown cURL error' : $error];
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        return ['status' => 'failed', 'html' => $body, 'error' => 'HTTP ' . $httpCode];
    }

    return ['status' => 'done', 'html' => $body, 'error' => null];
}

function apiRequest(string $method, string $url, ?array $payload, int $timeout): array
{
    $ch = curl_init($url);

    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error === '' ? 'Unknown cURL error' : $error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response from Master API');
    }

    if ($httpCode >= 400) {
        $message = (string) ($decoded['error'] ?? ('Master API returned HTTP ' . $httpCode));
        throw new RuntimeException($message);
    }

    return $decoded;
}
