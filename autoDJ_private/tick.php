<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$result = autodj_tick(['source' => 'cron']);
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
