<?php
declare(strict_types=1);

namespace Place;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $config = load_config();
    $origins = $config['allowed_origins'] ?? [];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    [, , $api] = make_app($config);
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
    }
    // Some shared hosts strip Authorization; .htaccess passes it on as REDIRECT_HTTP_AUTHORIZATION.
    $headers['authorization'] ??= (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $res = $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', array_map('strval', $_GET), $headers, (string) file_get_contents('php://input'));
    if (isset($res['redirect'])) {
        header('Location: ' . $res['redirect'], true, 302);
        exit;
    }
    http_response_code($res['status']);
    echo json_encode($res['body'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('parto-place: ' . get_class($e) . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Serverfehler']);
}
