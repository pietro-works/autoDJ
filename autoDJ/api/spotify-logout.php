<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

autodj_start_session();
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

autodj_redirect('../?logged_out=1');
