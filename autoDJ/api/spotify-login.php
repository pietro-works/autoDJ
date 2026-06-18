<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

autodj_start_session();
$config = autodj_config();
if (!$config['configured']) {
    autodj_redirect('../?error=setup');
}
if (autodj_admin_user_id() === '') {
    autodj_record_error('Spotify login blocked: no allowed admin account is configured.');
    autodj_redirect('../?error=private');
}

$state = bin2hex(random_bytes(18));
$_SESSION['autodj_oauth_state'] = $state;

$params = [
    'response_type' => 'code',
    'client_id' => $config['spotify_client_id'],
    'scope' => $config['spotify_scopes'],
    'redirect_uri' => $config['spotify_redirect_uri'],
    'state' => $state,
    'show_dialog' => 'false',
];

autodj_redirect('https://accounts.spotify.com/authorize?' . http_build_query($params));
