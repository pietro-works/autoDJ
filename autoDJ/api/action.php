<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

autodj_start_session();
autodj_require_admin();

$input = autodj_request_json();
$action = (string)($input['action'] ?? '');
$vibe = autodj_clean_vibe((string)($input['vibe'] ?? ''));
$state = autodj_read_state();
$message = 'Updated.';

if ($action === 'start') {
    if ($vibe === '') {
        autodj_error('Add a vibe before starting.', 422);
    }
    $state = autodj_new_running_state($state, $vibe);
    autodj_write_state($state);
    $tick = autodj_tick(['manual' => true, 'takeover' => true]);
    $message = 'autoDJ started. ' . ($tick['message'] ?? '');
} elseif ($action === 'restart') {
    if ($vibe === '') {
        autodj_error('Add a vibe before restarting.', 422);
    }
    $state = autodj_new_running_state($state, $vibe);
    autodj_write_state($state);
    $tick = autodj_tick(['manual' => true, 'takeover' => true]);
    $message = 'Session restarted. ' . ($tick['message'] ?? '');
} elseif ($action === 'stop') {
    $state['running'] = false;
    $state['started_device_id'] = '';
    $state['started_device_name'] = '';
    $state['last_heartbeat'] = null;
    $state['inactive_since'] = null;
    $state['last_active_at'] = null;
    $state['last_error'] = null;
    $state['updated_at'] = autodj_now();
    autodj_write_state($state);
    $message = 'autoDJ stopped.';
} elseif ($action === 'heartbeat') {
    if (!empty($state['running'])) {
        $state['last_heartbeat'] = autodj_now();
        $state['updated_at'] = autodj_now();
        autodj_write_state($state);
        $message = 'autoDJ heartbeat received.';
    } else {
        $message = 'autoDJ is stopped.';
    }
} elseif ($action === 'tick') {
    $tick = autodj_tick(['manual' => true]);
    $message = $tick['message'] ?? 'Tick complete.';
} else {
    autodj_error('Unknown action.', 400);
}

autodj_json_response([
    'ok' => true,
    'message' => $message,
    'status' => autodj_status_payload(),
]);
