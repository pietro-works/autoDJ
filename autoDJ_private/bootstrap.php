<?php
declare(strict_types=1);

function autodj_now(): string
{
    return gmdate('c');
}

function autodj_private_dir(): string
{
    return __DIR__;
}

function autodj_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = require __DIR__ . '/config.example.php';
    $configFile = __DIR__ . '/config.php';
    $actual = is_file($configFile) ? require $configFile : [];
    $config = array_merge($defaults, is_array($actual) ? $actual : []);

    foreach (['spotify_client_id', 'spotify_client_secret', 'openai_api_key', 'session_secret', 'cron_key', 'allowed_spotify_user_id'] as $key) {
        $env = getenv('AUTODJ_' . strtoupper($key));
        if ($env !== false && $env !== '') {
            $config[$key] = $env;
        }
    }

    $config['configured'] = is_file($configFile)
        && $config['spotify_client_id'] !== ''
        && $config['spotify_client_secret'] !== ''
        && $config['openai_api_key'] !== ''
        && $config['session_secret'] !== ''
        && $config['cron_key'] !== '';

    return $config;
}

function autodj_data_dir(): string
{
    $dir = autodj_private_dir() . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function autodj_path(string $name): string
{
    return autodj_data_dir() . '/' . $name;
}

function autodj_start_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = autodj_config();
    session_name('autodj_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/autoDJ',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();

    if (!isset($_SESSION['autodj_secret_hash'])) {
        $_SESSION['autodj_secret_hash'] = hash('sha256', (string)$config['session_secret']);
    } elseif (!hash_equals($_SESSION['autodj_secret_hash'], hash('sha256', (string)$config['session_secret']))) {
        $_SESSION = [];
        $_SESSION['autodj_secret_hash'] = hash('sha256', (string)$config['session_secret']);
    }
}

function autodj_read_json(string $path, array $default): array
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function autodj_write_json(string $path, array $value): void
{
    $tmp = $path . '.tmp';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode JSON.');
    }
    file_put_contents($tmp, $json . "\n", LOCK_EX);
    chmod($tmp, 0600);
    rename($tmp, $path);
}

function autodj_default_state(): array
{
    return [
        'running' => false,
        'vibe' => '',
        'session_id' => '',
        'current_track' => null,
        'feed' => [],
        'history' => [],
        'started_device_id' => '',
        'started_device_name' => '',
        'last_heartbeat' => null,
        'inactive_since' => null,
        'last_active_at' => null,
        'last_tick' => null,
        'last_error' => null,
        'admin_spotify_user_id' => '',
        'updated_at' => autodj_now(),
    ];
}

function autodj_read_state(): array
{
    return array_merge(autodj_default_state(), autodj_read_json(autodj_path('state.json'), autodj_default_state()));
}

function autodj_write_state(array $state): void
{
    autodj_write_json(autodj_path('state.json'), array_merge(autodj_default_state(), $state));
}

function autodj_seconds_since(?string $timestamp): ?int
{
    if (!$timestamp) {
        return null;
    }
    $time = strtotime($timestamp);
    return $time ? max(0, time() - $time) : null;
}

function autodj_stop_state(array $state, string $message): array
{
    $state['running'] = false;
    $state['last_error'] = $message;
    $state['updated_at'] = autodj_now();
    autodj_write_state($state);
    return ['ok' => true, 'message' => $message];
}

function autodj_new_running_state(array $state, string $vibe): array
{
    $now = autodj_now();
    $state['running'] = true;
    $state['vibe'] = $vibe;
    $state['session_id'] = autodj_new_session_id();
    $state['current_track'] = null;
    $state['history'] = [];
    $state['feed'] = [];
    $state['started_device_id'] = '';
    $state['started_device_name'] = '';
    $state['last_heartbeat'] = $now;
    $state['inactive_since'] = null;
    $state['last_active_at'] = null;
    $state['last_error'] = null;
    $state['updated_at'] = $now;
    return $state;
}

function autodj_track_is_owned(array $state, ?array $track): bool
{
    $uri = (string)($track['uri'] ?? '');
    if ($uri === '') {
        return false;
    }
    return isset(autodj_owned_uri_set($state)[$uri]);
}

function autodj_read_tokens(): array
{
    return autodj_read_json(autodj_path('tokens.json'), []);
}

function autodj_write_tokens(array $tokens): void
{
    $existing = autodj_read_tokens();
    $next = array_merge($existing, $tokens);
    if (isset($tokens['expires_in'])) {
        $next['expires_at'] = time() + max(60, (int)$tokens['expires_in'] - 60);
    }
    autodj_write_json(autodj_path('tokens.json'), $next);
}

function autodj_default_candidate_pool(): array
{
    return [
        'session_id' => '',
        'vibe_hash' => '',
        'created_at' => null,
        'updated_at' => null,
        'failures' => 0,
        'candidates' => [],
    ];
}

function autodj_read_candidate_pool(): array
{
    $pool = array_merge(autodj_default_candidate_pool(), autodj_read_json(autodj_path('candidates.json'), autodj_default_candidate_pool()));
    $pool['candidates'] = is_array($pool['candidates'] ?? null) ? array_values($pool['candidates']) : [];
    return $pool;
}

function autodj_write_candidate_pool(array $pool): void
{
    $next = array_merge(autodj_default_candidate_pool(), $pool);
    $next['candidates'] = is_array($next['candidates'] ?? null) ? array_values($next['candidates']) : [];
    autodj_write_json(autodj_path('candidates.json'), $next);
}

function autodj_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function autodj_error(string $message, int $status = 400): void
{
    autodj_json_response(['ok' => false, 'error' => $message], $status);
}

function autodj_redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

function autodj_request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function autodj_clean_vibe(string $vibe): string
{
    $vibe = trim(preg_replace('/\s+/', ' ', $vibe) ?? '');
    return substr($vibe, 0, 360);
}

function autodj_new_session_id(): string
{
    return 'ad_' . bin2hex(random_bytes(8));
}

function autodj_admin_user_id(): string
{
    $config = autodj_config();
    if ((string)$config['allowed_spotify_user_id'] !== '') {
        return (string)$config['allowed_spotify_user_id'];
    }
    $state = autodj_read_state();
    return (string)($state['admin_spotify_user_id'] ?? '');
}

function autodj_is_admin(): bool
{
    autodj_start_session();
    $allowed = autodj_admin_user_id();
    $sessionUser = (string)($_SESSION['autodj_spotify_user_id'] ?? '');
    return $allowed !== '' && $sessionUser !== '' && hash_equals($allowed, $sessionUser);
}

function autodj_require_admin(): void
{
    if (!autodj_config()['configured']) {
        autodj_error('Server config is not installed.', 503);
    }
    if (!autodj_is_admin()) {
        autodj_error('Spotify admin login required.', 401);
    }
}

function autodj_bind_or_validate_admin(array $profile): void
{
    $userId = (string)($profile['id'] ?? '');
    if ($userId === '') {
        throw new RuntimeException('Spotify profile did not include an id.');
    }

    $allowed = autodj_admin_user_id();
    if ($allowed !== '' && !hash_equals($allowed, $userId)) {
        throw new RuntimeException('This Spotify account is not allowed.');
    }

    if ($allowed === '') {
        throw new RuntimeException('autoDJ is private. Configure an allowed Spotify account before login.');
    }
}

function autodj_status_payload(): array
{
    autodj_start_session();
    $config = autodj_config();
    $state = autodj_read_state();
    $tokens = autodj_read_tokens();
    $isAdmin = autodj_is_admin();

    if (!$isAdmin) {
        return [
            'ok' => true,
            'configured' => (bool)$config['configured'],
            'admin' => false,
            'has_tokens' => false,
            'running' => false,
            'spotify_playing' => false,
            'vibe' => '',
            'session_id' => '',
            'current_track' => null,
            'spotify_queue' => [],
            'feed' => [],
            'last_tick' => null,
            'last_heartbeat' => null,
            'inactive_since' => null,
            'session_device' => null,
            'last_error' => null,
            'spotify_status_error' => null,
            'updated_at' => null,
        ];
    }

    $hasTokens = !empty($tokens['refresh_token']);
    $currentTrack = $state['current_track'];
    $spotifyQueue = [];
    $spotifyPlaying = false;
    $spotifyStatusError = null;

    if ((bool)$config['configured'] && $hasTokens) {
        try {
            $snapshot = autodj_spotify_playback_snapshot();
            $spotifyPlaying = (bool)$snapshot['playing'];
            $spotifyQueue = $snapshot['queue'];
            if ($snapshot['current_track'] !== null) {
                $currentTrack = $snapshot['current_track'];
                $stateUri = (string)($state['current_track']['uri'] ?? '');
                if ((string)$currentTrack['uri'] !== $stateUri) {
                    $state['current_track'] = $currentTrack;
                    $state['updated_at'] = autodj_now();
                    autodj_write_state($state);
                }
            }
        } catch (Throwable $error) {
            $spotifyStatusError = substr($error->getMessage(), 0, 220);
        }
    }

    return [
        'ok' => true,
        'configured' => (bool)$config['configured'],
        'admin' => true,
        'has_tokens' => $hasTokens,
        'running' => (bool)$state['running'],
        'spotify_playing' => $spotifyPlaying,
        'vibe' => (string)$state['vibe'],
        'session_id' => (string)$state['session_id'],
        'current_track' => $currentTrack,
        'spotify_queue' => $spotifyQueue,
        'feed' => array_values(array_slice($state['feed'] ?? [], 0, (int)$config['feed_limit'])),
        'last_tick' => $state['last_tick'],
        'last_heartbeat' => $state['last_heartbeat'],
        'inactive_since' => $state['inactive_since'],
        'session_device' => [
            'id_bound' => (string)($state['started_device_id'] ?? '') !== '',
            'name' => (string)($state['started_device_name'] ?? ''),
        ],
        'last_error' => $state['last_error'],
        'spotify_status_error' => $spotifyStatusError,
        'updated_at' => $state['updated_at'],
    ];
}

function autodj_http(string $method, string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }

    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => $options['timeout'] ?? 25,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($handle);
    if ($raw === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException($error);
    }
    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $responseBody = substr($raw, $headerSize);
    curl_close($handle);

    $decoded = $responseBody !== '' ? json_decode($responseBody, true) : null;
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded)
            ? (string)($decoded['error_description'] ?? $decoded['error']['message'] ?? $decoded['error'] ?? $responseBody)
            : $responseBody;
        throw new RuntimeException(trim($message) ?: 'HTTP ' . $status);
    }

    return [
        'status' => $status,
        'json' => is_array($decoded) ? $decoded : [],
        'body' => $responseBody,
    ];
}

function autodj_form_body(array $values): string
{
    return http_build_query($values, '', '&', PHP_QUERY_RFC3986);
}

function autodj_spotify_basic_header(): string
{
    $config = autodj_config();
    return 'Authorization: Basic ' . base64_encode($config['spotify_client_id'] . ':' . $config['spotify_client_secret']);
}

function autodj_spotify_profile_from_access_token(string $accessToken): array
{
    if ($accessToken === '') {
        throw new RuntimeException('Spotify access token was not returned.');
    }

    $response = autodj_http('GET', 'https://api.spotify.com/v1/me', [
        'headers' => [
            'Authorization: Bearer ' . $accessToken,
        ],
    ]);

    return $response['json'];
}

function autodj_spotify_exchange_code(string $code): array
{
    $config = autodj_config();
    $response = autodj_http('POST', 'https://accounts.spotify.com/api/token', [
        'headers' => [
            autodj_spotify_basic_header(),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        'body' => autodj_form_body([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config['spotify_redirect_uri'],
        ]),
    ]);
    return $response['json'];
}

function autodj_spotify_access_token(): string
{
    $tokens = autodj_read_tokens();
    if (!empty($tokens['access_token']) && (int)($tokens['expires_at'] ?? 0) > time() + 30) {
        return (string)$tokens['access_token'];
    }

    $refreshToken = (string)($tokens['refresh_token'] ?? '');
    if ($refreshToken === '') {
        throw new RuntimeException('Spotify refresh token is missing. Connect Spotify first.');
    }

    $response = autodj_http('POST', 'https://accounts.spotify.com/api/token', [
        'headers' => [
            autodj_spotify_basic_header(),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        'body' => autodj_form_body([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]),
    ]);

    $next = $response['json'];
    if (empty($next['refresh_token'])) {
        $next['refresh_token'] = $refreshToken;
    }
    autodj_write_tokens($next);
    return (string)$next['access_token'];
}

function autodj_spotify_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    $url = 'https://api.spotify.com' . $path;
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $headers = ['Authorization: Bearer ' . autodj_spotify_access_token()];
    $payload = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    return autodj_http($method, $url, [
        'headers' => $headers,
        'body' => $payload,
        'timeout' => 20,
    ])['json'];
}

function autodj_spotify_device(): ?array
{
    $payload = autodj_spotify_request('GET', '/v1/me/player/devices');
    $devices = is_array($payload['devices'] ?? null) ? $payload['devices'] : [];
    $fallback = null;

    foreach ($devices as $device) {
        if (!empty($device['is_restricted'])) {
            continue;
        }
        $id = (string)($device['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $candidate = [
            'id' => $id,
            'name' => (string)($device['name'] ?? ''),
            'type' => (string)($device['type'] ?? ''),
        ];
        if (!empty($device['is_active'])) {
            return $candidate;
        }
        $fallback ??= $candidate;
    }

    return $fallback;
}

function autodj_playback_device(array $playback): array
{
    $device = is_array($playback['device'] ?? null) ? $playback['device'] : [];
    return [
        'id' => (string)($device['id'] ?? ''),
        'name' => (string)($device['name'] ?? ''),
        'type' => (string)($device['type'] ?? ''),
    ];
}

function autodj_bind_session_device(array &$state, array $device): void
{
    $id = (string)($device['id'] ?? '');
    if ($id === '') {
        return;
    }
    $state['started_device_id'] = $id;
    $state['started_device_name'] = (string)($device['name'] ?? '');
}

function autodj_spotify_start_playback(array $uris): ?array
{
    $uris = array_values(array_filter(array_map('strval', $uris)));
    if (!$uris) {
        throw new RuntimeException('No Spotify tracks were selected for playback.');
    }

    $query = [];
    $device = autodj_spotify_device();
    if ($device && (string)($device['id'] ?? '') !== '') {
        $query['device_id'] = (string)$device['id'];
    }

    autodj_spotify_request('PUT', '/v1/me/player/play', $query, [
        'uris' => $uris,
        'position_ms' => 0,
    ]);

    return $device;
}

function autodj_record_error(string $message): void
{
    $state = autodj_read_state();
    $state['last_error'] = substr($message, 0, 260);
    $state['updated_at'] = autodj_now();
    autodj_write_state($state);
}

function autodj_track_entry(array $track, string $source): array
{
    $images = $track['album']['images'] ?? [];
    $image = is_array($images) && isset($images[0]['url']) ? (string)$images[0]['url'] : '';
    $artists = array_map(static fn(array $artist): string => (string)($artist['name'] ?? ''), $track['artists'] ?? []);
    $artists = array_values(array_filter($artists));

    return [
        'uri' => (string)($track['uri'] ?? ''),
        'id' => (string)($track['id'] ?? ''),
        'name' => (string)($track['name'] ?? ''),
        'artists' => implode(', ', $artists),
        'spotify_url' => (string)($track['external_urls']['spotify'] ?? ''),
        'image_url' => $image,
        'source' => $source,
        'added_at' => autodj_now(),
    ];
}

function autodj_spotify_playback_snapshot(): array
{
    $snapshot = [
        'playing' => false,
        'current_track' => null,
        'queue' => [],
    ];

    $playback = autodj_spotify_request('GET', '/v1/me/player');
    $item = is_array($playback['item'] ?? null) ? $playback['item'] : null;
    if ($item && (string)($item['type'] ?? 'track') === 'track' && (string)($item['uri'] ?? '') !== '') {
        $snapshot['playing'] = !empty($playback['is_playing']);
        $snapshot['current_track'] = autodj_track_entry($item, 'current');
    }

    try {
        $queuePayload = autodj_spotify_request('GET', '/v1/me/player/queue');
        $queue = is_array($queuePayload['queue'] ?? null) ? $queuePayload['queue'] : [];
        foreach ($queue as $track) {
            if (!is_array($track) || (string)($track['type'] ?? 'track') !== 'track' || (string)($track['uri'] ?? '') === '') {
                continue;
            }
            $snapshot['queue'][] = autodj_track_entry($track, 'spotify_queue');
            if (count($snapshot['queue']) >= 12) {
                break;
            }
        }
    } catch (Throwable) {
        // Some Spotify states expose current playback but not a queue.
    }

    return $snapshot;
}

function autodj_vibe_hash(array $state): string
{
    return hash('sha256', autodj_clean_vibe((string)($state['vibe'] ?? '')));
}

function autodj_candidate_key(string $query): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? ''));
}

function autodj_candidate_from_openai(array $candidate): ?array
{
    $query = substr(trim((string)($candidate['query'] ?? '')), 0, 160);
    if ($query === '') {
        return null;
    }

    $energy = (string)($candidate['energy'] ?? 'medium');
    if (!in_array($energy, ['low', 'medium', 'high'], true)) {
        $energy = 'medium';
    }

    $role = (string)($candidate['role'] ?? 'main');
    if (!in_array($role, ['warmup', 'main', 'bridge', 'reset'], true)) {
        $role = 'main';
    }

    return [
        'query' => $query,
        'reason' => substr(trim((string)($candidate['reason'] ?? '')), 0, 220),
        'energy' => $energy,
        'role' => $role,
        'used' => false,
        'failed' => false,
        'created_at' => autodj_now(),
        'used_at' => null,
        'failed_at' => null,
    ];
}

function autodj_candidate_unused_count(array $pool): int
{
    $count = 0;
    foreach (($pool['candidates'] ?? []) as $candidate) {
        if (empty($candidate['used']) && empty($candidate['failed']) && trim((string)($candidate['query'] ?? '')) !== '') {
            $count++;
        }
    }
    return $count;
}

function autodj_candidate_pool_should_reset(array $pool, array $state, array $config): bool
{
    if ((string)($pool['session_id'] ?? '') !== (string)($state['session_id'] ?? '')) {
        return true;
    }
    if ((string)($pool['vibe_hash'] ?? '') !== autodj_vibe_hash($state)) {
        return true;
    }
    if ((int)($pool['failures'] ?? 0) >= max(1, (int)$config['candidate_pool_max_failures'])) {
        return true;
    }

    $createdAt = strtotime((string)($pool['created_at'] ?? ''));
    $ttlSeconds = max(15, (int)$config['candidate_pool_ttl_minutes']) * 60;
    return !$createdAt || $createdAt < time() - $ttlSeconds;
}

function autodj_candidate_pool_base(array $state): array
{
    return [
        'session_id' => (string)($state['session_id'] ?? ''),
        'vibe_hash' => autodj_vibe_hash($state),
        'created_at' => autodj_now(),
        'updated_at' => autodj_now(),
        'failures' => 0,
        'candidates' => [],
    ];
}

function autodj_pool_failed_queries(array $pool): array
{
    $failed = [];
    foreach (($pool['candidates'] ?? []) as $candidate) {
        if (!empty($candidate['failed'])) {
            $failed[] = (string)($candidate['query'] ?? '');
        }
    }
    return array_values(array_filter(array_slice($failed, -12)));
}

function autodj_openai_candidates(array $context, int $targetCount): array
{
    $config = autodj_config();
    $targetCount = min(20, max(8, $targetCount));
    $minCount = min(12, $targetCount);
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'candidates' => [
                'type' => 'array',
                'minItems' => $minCount,
                'maxItems' => $targetCount,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'energy' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        'role' => ['type' => 'string', 'enum' => ['warmup', 'main', 'bridge', 'reset']],
                    ],
                    'required' => ['query', 'reason', 'energy', 'role'],
                ],
            ],
        ],
        'required' => ['candidates'],
    ];

    $request = [
        'model' => (string)($config['openai_queue_model'] ?? $config['openai_model']),
        'store' => false,
        'reasoning' => ['effort' => 'low'],
        'text' => [
            'verbosity' => 'low',
            'format' => [
                'type' => 'json_schema',
                'name' => 'autodj_candidates',
                'strict' => true,
                'schema' => $schema,
            ],
        ],
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => 'You are a Spotify radio programming assistant. Generate real track search queries for a continuous live queue. Favor playable, findable Spotify tracks. Keep the session coherent, avoid repeats, and include enough adjacent variety to prevent the queue from feeling mechanical.',
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => json_encode($context, JSON_UNESCAPED_SLASHES),
                    ],
                ],
            ],
        ],
    ];

    $response = autodj_http('POST', 'https://api.openai.com/v1/responses', [
        'headers' => [
            'Authorization: Bearer ' . $config['openai_api_key'],
            'Content-Type: application/json',
        ],
        'body' => json_encode($request, JSON_UNESCAPED_SLASHES),
        'timeout' => 35,
    ])['json'];

    $text = (string)($response['output_text'] ?? '');
    if ($text === '' && isset($response['output']) && is_array($response['output'])) {
        foreach ($response['output'] as $item) {
            foreach (($item['content'] ?? []) as $part) {
                if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                    $text .= (string)$part['text'];
                }
            }
        }
    }

    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !isset($decoded['candidates']) || !is_array($decoded['candidates'])) {
        throw new RuntimeException('OpenAI returned no usable candidates.');
    }

    return $decoded['candidates'];
}

function autodj_recent_context(array $state, ?array $currentTrack, array $queue, array $pool = []): array
{
    $recent = array_slice($state['history'] ?? [], 0, 16);
    $queueSummary = array_slice(array_map(static function (array $track): array {
        return [
            'name' => (string)($track['name'] ?? ''),
            'artists' => implode(', ', array_map(static fn(array $artist): string => (string)($artist['name'] ?? ''), $track['artists'] ?? [])),
        ];
    }, $queue), 0, 8);

    return [
        'goal' => 'Pick the next Spotify track search queries for a continuous radio queue.',
        'vibe' => (string)$state['vibe'],
        'current_track' => $currentTrack ? [
            'name' => (string)($currentTrack['name'] ?? ''),
            'artists' => implode(', ', array_map(static fn(array $artist): string => (string)($artist['name'] ?? ''), $currentTrack['artists'] ?? [])),
        ] : null,
        'upcoming_queue' => $queueSummary,
        'recently_added' => array_map(static fn(array $track): string => trim(($track['artists'] ?? '') . ' - ' . ($track['name'] ?? '')), $recent),
        'failed_candidates' => autodj_pool_failed_queries($pool),
        'candidate_target' => (int)autodj_config()['candidate_pool_refill'],
        'rules' => [
            'Return candidates as artist and track title search strings where possible.',
            'Prefer real Spotify tracks.',
            'Avoid duplicate artists and tracks from recently_added where possible.',
            'Include a mix of obvious anchors and deeper adjacent picks.',
            'Keep energy coherent with the stated vibe.',
        ],
    ];
}

function autodj_refill_candidate_pool(array $state, ?array $currentTrack, array $queue, array $pool): array
{
    $config = autodj_config();
    if (autodj_candidate_pool_should_reset($pool, $state, $config)) {
        $pool = autodj_candidate_pool_base($state);
    }

    if (autodj_candidate_unused_count($pool) >= max(1, (int)$config['candidate_pool_min'])) {
        return $pool;
    }

    $targetCount = (int)$config['candidate_pool_refill'];
    $fresh = autodj_openai_candidates(autodj_recent_context($state, $currentTrack, $queue, $pool), $targetCount);
    $seen = [];
    foreach (($pool['candidates'] ?? []) as $candidate) {
        $key = autodj_candidate_key((string)($candidate['query'] ?? ''));
        if ($key !== '') {
            $seen[$key] = true;
        }
    }

    foreach ($fresh as $candidate) {
        $normalized = autodj_candidate_from_openai(is_array($candidate) ? $candidate : []);
        if (!$normalized) {
            continue;
        }
        $key = autodj_candidate_key((string)$normalized['query']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $pool['candidates'][] = $normalized;
    }

    $pool['candidates'] = array_slice(array_values($pool['candidates']), -max(8, (int)$config['candidate_pool_max']));
    $pool['updated_at'] = autodj_now();
    autodj_write_candidate_pool($pool);
    return $pool;
}

function autodj_duplicate_uri_set(array $state, ?array $currentTrack, array $queue): array
{
    $uris = [];
    if ($currentTrack && isset($currentTrack['uri'])) {
        $uris[(string)$currentTrack['uri']] = true;
    }
    foreach (array_slice($queue, 0, 12) as $track) {
        if (isset($track['uri'])) {
            $uris[(string)$track['uri']] = true;
        }
    }
    foreach (($state['history'] ?? []) as $track) {
        if (isset($track['uri'])) {
            $uris[(string)$track['uri']] = true;
        }
    }
    return $uris;
}

function autodj_owned_uri_set(array $state): array
{
    $uris = [];
    foreach (['history', 'feed'] as $bucket) {
        foreach (($state[$bucket] ?? []) as $track) {
            $uri = (string)($track['uri'] ?? '');
            if ($uri !== '' && (string)($track['source'] ?? '') === 'queued') {
                $uris[$uri] = true;
            }
        }
    }
    return $uris;
}

function autodj_owned_queue_count(array $state, array $queue): int
{
    $owned = autodj_owned_uri_set($state);
    if (!$owned) {
        return 0;
    }

    $count = 0;
    foreach ($queue as $track) {
        $uri = (string)($track['uri'] ?? '');
        if ($uri !== '' && isset($owned[$uri])) {
            $count++;
        }
    }
    return $count;
}

function autodj_search_track(string $query): array
{
    $result = autodj_spotify_request('GET', '/v1/search', [
        'q' => $query,
        'type' => 'track',
        'limit' => '3',
    ]);
    return $result['tracks']['items'] ?? [];
}

function autodj_select_candidate_tracks(array &$pool, array $state, ?array $currentTrack, array $queue, int $limit): array
{
    $selected = [];
    $duplicates = autodj_duplicate_uri_set($state, $currentTrack, $queue);

    foreach (($pool['candidates'] ?? []) as $index => $candidate) {
        if (count($selected) >= $limit) {
            break;
        }
        if (!empty($candidate['used']) || !empty($candidate['failed'])) {
            continue;
        }

        $query = trim((string)($candidate['query'] ?? ''));
        if ($query === '') {
            continue;
        }

        $found = false;
        foreach (autodj_search_track($query) as $track) {
            $uri = (string)($track['uri'] ?? '');
            if ($uri === '' || isset($duplicates[$uri])) {
                continue;
            }
            $selected[] = [
                'index' => $index,
                'track' => $track,
                'entry' => autodj_track_entry($track, 'queued'),
            ];
            $duplicates[$uri] = true;
            $found = true;
            break;
        }

        if (!$found) {
            $pool['candidates'][$index]['failed'] = true;
            $pool['candidates'][$index]['failed_at'] = autodj_now();
            $pool['updated_at'] = autodj_now();
            $pool['failures'] = (int)($pool['failures'] ?? 0) + 1;
        }
    }

    return $selected;
}

function autodj_apply_selected_tracks(array &$state, array &$pool, array $selected, bool $setCurrent): array
{
    $added = [];
    $now = autodj_now();

    foreach ($selected as $selection) {
        $index = (int)$selection['index'];
        $pool['candidates'][$index]['used'] = true;
        $pool['candidates'][$index]['used_at'] = $now;
        $entry = $selection['entry'];
        $added[] = $entry;
    }

    foreach (array_reverse($added) as $entry) {
        array_unshift($state['history'], $entry);
        array_unshift($state['feed'], $entry);
    }

    if ($setCurrent && isset($selected[0]['track'])) {
        $state['current_track'] = autodj_track_entry($selected[0]['track'], 'current');
    }

    $config = autodj_config();
    $state['history'] = array_slice($state['history'], 0, (int)$config['history_limit']);
    $state['feed'] = array_slice($state['feed'], 0, (int)$config['feed_limit']);
    $pool['updated_at'] = $now;
    $pool['failures'] = 0;

    return $added;
}

function autodj_tick(array $options = []): array
{
    $lockPath = autodj_path('tick.lock');
    $lock = fopen($lockPath, 'c');
    if (!$lock) {
        throw new RuntimeException('Could not open tick lock.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['ok' => true, 'message' => 'Tick already running.'];
    }

    try {
        $config = autodj_config();
        $state = autodj_read_state();
        if (!$config['configured']) {
            return ['ok' => false, 'message' => 'Server config is not installed.'];
        }
        if (empty($state['running'])) {
            return ['ok' => true, 'message' => 'autoDJ is stopped.'];
        }

        $manual = !empty($options['manual']);
        $takeover = !empty($options['takeover']);
        if (!$manual && !$takeover) {
            $heartbeatAge = autodj_seconds_since((string)($state['last_heartbeat'] ?? ''));
            $heartbeatLimit = max(30, (int)$config['heartbeat_timeout_seconds']);
            if ($heartbeatAge === null || $heartbeatAge > $heartbeatLimit) {
                return autodj_stop_state($state, 'autoDJ stopped because the control page is not active.');
            }
        }

        $playback = autodj_spotify_request('GET', '/v1/me/player');
        $hasActivePlayback = $playback && !empty($playback['is_playing']) && !empty($playback['item']);
        if (!$hasActivePlayback && !$takeover) {
            $now = autodj_now();
            if (empty($state['inactive_since'])) {
                $state['inactive_since'] = $now;
            }
            $inactiveAge = autodj_seconds_since((string)$state['inactive_since']) ?? 0;
            $inactiveLimit = max(1, (int)$config['inactive_stop_minutes']) * 60;
            if ($inactiveAge >= $inactiveLimit) {
                return autodj_stop_state($state, 'autoDJ stopped after Spotify playback stayed inactive.');
            }
            $state['last_tick'] = autodj_now();
            $state['last_error'] = 'Spotify playback is not active.';
            $state['updated_at'] = $now;
            autodj_write_state($state);
            return ['ok' => true, 'message' => 'Spotify playback is not active.'];
        }

        $currentTrack = $hasActivePlayback ? $playback['item'] : null;
        $queue = [];
        if ($hasActivePlayback) {
            $now = autodj_now();
            $device = autodj_playback_device($playback);
            if (!$takeover) {
                $sessionDeviceId = (string)($state['started_device_id'] ?? '');
                $playbackDeviceId = (string)($device['id'] ?? '');
                if ($sessionDeviceId === '' && $playbackDeviceId !== '') {
                    autodj_bind_session_device($state, $device);
                } elseif ($sessionDeviceId !== '' && $playbackDeviceId !== '' && !hash_equals($sessionDeviceId, $playbackDeviceId)) {
                    return autodj_stop_state($state, 'autoDJ stopped because Spotify playback moved to another device.');
                }
                if (!autodj_track_is_owned($state, $currentTrack)) {
                    return autodj_stop_state($state, 'autoDJ stopped because Spotify playback left this session.');
                }
            }

            $queuePayload = autodj_spotify_request('GET', '/v1/me/player/queue');
            $queue = is_array($queuePayload['queue'] ?? null) ? $queuePayload['queue'] : [];
            $state['current_track'] = autodj_track_entry($currentTrack, 'current');
            $state['inactive_since'] = null;
            $state['last_active_at'] = $now;
        }

        $ownedQueueCount = autodj_owned_queue_count($state, $queue);
        if ($ownedQueueCount >= (int)$config['queue_threshold']) {
            $state['last_tick'] = autodj_now();
            $state['last_error'] = null;
            autodj_write_state($state);
            return ['ok' => true, 'message' => 'autoDJ queue is already above threshold.'];
        }

        $added = [];
        $targetDepth = max((int)$config['queue_threshold'], (int)$config['target_queue_depth']);
        $tracksNeeded = max(1, $targetDepth - $ownedQueueCount);
        $maxAdds = min(max(1, (int)$config['max_adds_per_tick']), $tracksNeeded);
        $duplicates = autodj_duplicate_uri_set($state, $currentTrack, $queue);
        $pool = autodj_refill_candidate_pool($state, $currentTrack, $queue, autodj_read_candidate_pool());

        if ($takeover) {
            $takeoverLimit = min($targetDepth, max(1, (int)$config['takeover_track_count']));
            $selected = autodj_select_candidate_tracks($pool, $state, $currentTrack, $queue, $takeoverLimit);
            if (!$selected) {
                autodj_write_candidate_pool($pool);
                $state['last_tick'] = autodj_now();
                $state['last_error'] = 'No non-duplicate Spotify candidate found.';
                $state['updated_at'] = autodj_now();
                autodj_write_state($state);
                return ['ok' => true, 'message' => 'No track added.'];
            }

            $startedDevice = autodj_spotify_start_playback(array_map(static fn(array $selection): string => (string)$selection['track']['uri'], $selected));
            $added = autodj_apply_selected_tracks($state, $pool, $selected, true);
            if ($startedDevice) {
                autodj_bind_session_device($state, $startedDevice);
            }
            $state['inactive_since'] = null;
            $state['last_active_at'] = autodj_now();
            $state['last_tick'] = autodj_now();
            $state['last_error'] = null;
            $state['updated_at'] = autodj_now();
            autodj_write_state($state);
            autodj_write_candidate_pool($pool);

            return [
                'ok' => true,
                'message' => 'Started autoDJ playback with ' . count($added) . ' track(s).',
                'added' => $added,
            ];
        }

        for ($i = 0; $i < $maxAdds; $i++) {
            $queuedTrack = null;
            $queuedSpotifyTrack = null;

            foreach (($pool['candidates'] ?? []) as $index => $candidate) {
                if (!empty($candidate['used']) || !empty($candidate['failed'])) {
                    continue;
                }
                $query = trim((string)($candidate['query'] ?? ''));
                if ($query === '') {
                    continue;
                }
                $found = false;
                foreach (autodj_search_track($query) as $track) {
                    $uri = (string)($track['uri'] ?? '');
                    if ($uri === '' || isset($duplicates[$uri])) {
                        continue;
                    }
                    autodj_spotify_request('POST', '/v1/me/player/queue', ['uri' => $uri]);
                    $queuedTrack = autodj_track_entry($track, 'queued');
                    $queuedSpotifyTrack = $track;
                    $duplicates[$uri] = true;
                    $pool['candidates'][$index]['used'] = true;
                    $pool['candidates'][$index]['used_at'] = autodj_now();
                    $pool['updated_at'] = autodj_now();
                    $pool['failures'] = 0;
                    autodj_write_candidate_pool($pool);
                    $found = true;
                    break 2;
                }
                if (!$found) {
                    $pool['candidates'][$index]['failed'] = true;
                    $pool['candidates'][$index]['failed_at'] = autodj_now();
                    $pool['updated_at'] = autodj_now();
                    $pool['failures'] = (int)($pool['failures'] ?? 0) + 1;
                    autodj_write_candidate_pool($pool);
                }
            }

            if (!$queuedTrack) {
                break;
            }

            if ($queuedSpotifyTrack) {
                $queue[] = $queuedSpotifyTrack;
                $ownedQueueCount++;
            }
            array_unshift($state['history'], $queuedTrack);
            array_unshift($state['feed'], $queuedTrack);
            $state['history'] = array_slice($state['history'], 0, (int)$config['history_limit']);
            $state['feed'] = array_slice($state['feed'], 0, (int)$config['feed_limit']);
            $added[] = $queuedTrack;
        }

        $state['last_tick'] = autodj_now();
        $state['last_error'] = $added ? null : 'No non-duplicate Spotify candidate found.';
        $state['updated_at'] = autodj_now();
        autodj_write_state($state);

        return [
            'ok' => true,
            'message' => $added ? 'Added ' . count($added) . ' track(s).' : 'No track added.',
            'added' => $added,
        ];
    } catch (Throwable $error) {
        autodj_record_error($error->getMessage());
        return ['ok' => false, 'message' => $error->getMessage()];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
