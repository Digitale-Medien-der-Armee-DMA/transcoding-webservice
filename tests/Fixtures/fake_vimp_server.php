<?php

$logFile = getenv('FAKE_VIMP_LOG');
$sourceBody = getenv('FAKE_VIMP_SOURCE_BODY');
$configFile = getenv('FAKE_VIMP_CONFIG');
$config = $configFile && is_file($configFile)
    ? (json_decode((string) file_get_contents($configFile), true) ?: [])
    : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');

if ($logFile) {
    file_put_contents($logFile, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $path,
        'body' => $body,
        'headers' => function_exists('getallheaders') ? getallheaders() : [],
    ]) . PHP_EOL, FILE_APPEND);
}

if ($path === '/__ping') {
    header('Content-Type: text/plain');
    echo 'ok';
    return;
}

if (strpos($path, '/transcoderwebservice/source/') === 0) {
    header('Content-Type: video/mp4');
    echo $sourceBody !== false ? $sourceBody : 'fake-video';
    return;
}

if ($path === '/transcoderwebservice/callback') {
    header('Content-Type: application/json');
    $payload = json_decode($body, true);

    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['message' => 'invalid json']);
        return;
    }

    if (!empty($config['allowed_mediakeys'])
        && !in_array($payload['mediakey'] ?? null, $config['allowed_mediakeys'], true)) {
        http_response_code(404);
        echo json_encode(['message' => 'unknown mediakey']);
        return;
    }

    if (isset($payload['medium']['label']) && !empty($config['allowed_labels'])
        && !in_array($payload['medium']['label'], $config['allowed_labels'], true)) {
        http_response_code(404);
        echo json_encode(['message' => 'unknown medium label']);
        return;
    }

    if (isset($payload['medium']['url']) && !empty($config['required_url_prefix'])
        && strpos($payload['medium']['url'], $config['required_url_prefix']) !== 0) {
        http_response_code(404);
        echo json_encode(['message' => 'unreachable medium url']);
        return;
    }

    http_response_code((int) ($config['callback_status'] ?? 200));
    echo json_encode($config['callback_body'] ?? ['ok' => true]);
    return;
}

if ($path === '/transcoderwebservice/version') {
    header('Content-Type: application/json');
    echo json_encode(['version' => 'fake-vimp']);
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['message' => 'not found']);
