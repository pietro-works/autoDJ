<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

autodj_start_session();
$config = autodj_config();

$expectedState = $_SESSION['autodj_oauth_state'] ?? '';
$actualState = (string)($_GET['state'] ?? '');
unset($_SESSION['autodj_oauth_state']);

if (!$config['configured'] || !$expectedState || !hash_equals($expectedState, $actualState)) {
    autodj_redirect('../?error=oauth_state');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    autodj_redirect('../?error=oauth_denied');
}

try {
    $token = autodj_spotify_exchange_code($code);
    $profile = autodj_spotify_profile_from_access_token((string)($token['access_token'] ?? ''));
    autodj_bind_or_validate_admin($profile);
    autodj_write_tokens($token);
    $_SESSION['autodj_spotify_user_id'] = (string)($profile['id'] ?? '');
    autodj_redirect('../?connected=1');
} catch (Throwable $error) {
    autodj_record_error('Spotify login failed: ' . $error->getMessage());
    autodj_redirect('../?error=spotify_login');
}
