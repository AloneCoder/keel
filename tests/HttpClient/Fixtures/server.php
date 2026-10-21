<?php

declare(strict_types=1);

// Test server for CurlClientTest, run with php -S. Echoes what it received as JSON.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', \PHP_URL_PATH);
if ($path === '/redirect') {
    header('Location: /echo?from=redirect', true, 302);
    exit;
}
if ($path === '/slow') {
    usleep(300_000);
}
if ($path === '/status') {
    $code = $_GET['code'] ?? '500';
    http_response_code(is_string($code) ? (int) $code : 500);
    header('X-Custom: yes');
    header('X-Multi: a');
    header('X-Multi: b', false);
    echo 'nope';
    exit;
}
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $_GET,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
], \JSON_THROW_ON_ERROR);
