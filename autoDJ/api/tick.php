<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$config = autodj_config();
$key = (string)($_GET['key'] ?? '');
if (!$config['cron_key'] || !hash_equals($config['cron_key'], $key)) {
    autodj_error('Forbidden.', 403);
}

autodj_json_response(autodj_tick(['manual' => false, 'source' => 'web']));
