<?php

// CurlTransportTest 使用的内置服务器路由
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/ok':
        echo 'ok';
        break;
    case '/status':
        http_response_code((int) ($_GET['code'] ?? 200));
        echo 'status';
        break;
    case '/sleep':
        usleep((int) ($_GET['ms'] ?? 0) * 1000);
        echo 'slept';
        break;
    case '/echo':
        header('Content-Type: application/json');
        header('X-Multi: a', false);
        header('X-Multi: b', false);
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'custom' => $_SERVER['HTTP_X_CUSTOM'] ?? null,
            'body' => file_get_contents('php://input'),
        ]);
        break;
    default:
        http_response_code(404);
}
