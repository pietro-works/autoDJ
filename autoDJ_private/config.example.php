<?php
declare(strict_types=1);

return [
    'base_url' => 'http://127.0.0.1:8000',
    'spotify_client_id' => '',
    'spotify_client_secret' => '',
    'spotify_redirect_uri' => 'http://127.0.0.1:8000/api/spotify-callback.php',
    'spotify_scopes' => 'user-read-playback-state user-read-currently-playing user-modify-playback-state user-read-private',
    'openai_api_key' => '',
    'openai_model' => 'gpt-5.5',
    'openai_queue_model' => 'gpt-5.4-mini',
    'session_secret' => '',
    'cron_key' => '',
    'allowed_spotify_user_id' => '',
    'queue_threshold' => 3,
    'target_queue_depth' => 3,
    'max_adds_per_tick' => 1,
    'takeover_track_count' => 3,
    'heartbeat_timeout_seconds' => 90,
    'inactive_stop_minutes' => 10,
    'candidate_pool_min' => 4,
    'candidate_pool_refill' => 16,
    'candidate_pool_max' => 32,
    'candidate_pool_ttl_minutes' => 75,
    'candidate_pool_max_failures' => 8,
    'history_limit' => 80,
    'feed_limit' => 40,
];
