<?php
declare(strict_types=1);

if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === '_bootstrap.php') {
    http_response_code(404);
    exit;
}

$candidates = [
    getenv('AUTODJ_PRIVATE_DIR') ?: null,
    dirname(__DIR__, 2) . '/autoDJ_private',
    dirname(__DIR__, 3) . '/.autodj',
    '/home/' . get_current_user() . '/.autodj',
];

foreach ($candidates as $candidate) {
    if ($candidate && is_file($candidate . '/bootstrap.php')) {
        require_once $candidate . '/bootstrap.php';
        return;
    }
}

http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'autoDJ private runtime was not found.',
], JSON_UNESCAPED_SLASHES);
