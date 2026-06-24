<?php

$logFile = getenv('FAKE_VIMP_LOG');
$sourceBody = getenv('FAKE_VIMP_SOURCE_BODY');
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
    echo json_encode(['ok' => true]);
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
