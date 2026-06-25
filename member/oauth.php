<?php
/**
 * Member Portal — OAuth Callback Handler
 * Google + Facebook OAuth2 Flow
 */
require_once '../includes/config.php';
require_once '../includes/member-auth.php';
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
$code     = (string)($_GET['code'] ?? '');
$state    = (string)($_GET['state'] ?? '');
$error_p  = (string)($_GET['error'] ?? '');
if (!in_array($provider, ['google', 'facebook'], true)) {
    oauthRedirectError($_t('अज्ञात OAuth provider।', 'Unknown OAuth provider.'));
}
$code  = mb_substr(str_replace("\0", '', $code), 0, 4096, 'UTF-8');
$state = mb_substr(str_replace("\0", '', $state), 0, 512, 'UTF-8');

function oauthRedirectError($msg) {
    $_SESSION['oauth_error'] = $msg;
    header('Location: ' . SITE_URL . 'member/login.php?tab=login');
    exit;
}

/* State CSRF check — state is ALWAYS required */
if ($error_p) {
    oauthRedirectError($_t('OAuth रद्द भयो। पुनः प्रयास गर्नुहोस्।', 'OAuth cancelled. Please try again.'));
}
if (!$code || !$state || !isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    oauthRedirectError('OAuth security check failed. Please try again.');
}
unset($_SESSION['oauth_state']);

/* ── Google OAuth ── */
if ($provider === 'google') {
    $clientId     = getSetting('google_client_id', '');
    $clientSecret = getSetting('google_client_secret', '');
    $redirectUri  = SITE_URL . 'member/oauth.php?provider=google';

    if (!$clientId || !$clientSecret) oauthRedirectError($_t('Google OAuth configure भएको छैन। Admin लाई भेट्नुहोस्।', 'Google OAuth is not configured. Please contact admin.'));

    /* Exchange code for token */
    $tokenResp = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'code'          => $code,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ]),
        ]
    ]));

    if (!$tokenResp) oauthRedirectError('Google token exchange failed.');
    $tokenData = json_decode($tokenResp, true);
    $accessToken = $tokenData['access_token'] ?? '';
    if (!$accessToken) oauthRedirectError('Google token invalid.');

    /* Get user info */
    $userResp = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, stream_context_create([
        'http' => ['header' => 'Authorization: Bearer ' . $accessToken]
    ]));

    if (!$userResp) oauthRedirectError('Google user info fetch failed.');
    $user = json_decode($userResp, true);

    $googleId  = $user['id']         ?? '';
    $name      = $user['name']       ?? 'Google User';
    $email     = $user['email']      ?? '';
    $avatarUrl = $user['picture']    ?? '';

    if (!$googleId) oauthRedirectError('Google user ID not received.');

    $res = memberOAuthLogin('google', $googleId, $name, $email, $avatarUrl);
    if (isset($res['error'])) oauthRedirectError($res['error']);

    header('Location: ' . SITE_URL . 'member/?welcome=google');
    exit;
}

/* ── Facebook OAuth ── */
if ($provider === 'facebook') {
    $appId     = getSetting('facebook_app_id', '');
    $appSecret = getSetting('facebook_app_secret', '');
    $redirectUri = SITE_URL . 'member/oauth.php?provider=facebook';

    if (!$appId || !$appSecret) oauthRedirectError($_t('Facebook OAuth configure भएको छैन। Admin लाई भेट्नुहोस्।', 'Facebook OAuth is not configured. Please contact admin.'));

    /* Exchange code for token */
    $tokenUrl = 'https://graph.facebook.com/v18.0/oauth/access_token?' . http_build_query([
        'client_id'     => $appId,
        'client_secret' => $appSecret,
        'redirect_uri'  => $redirectUri,
        'code'          => $code,
    ]);
    $tokenResp = file_get_contents($tokenUrl);
    if (!$tokenResp) oauthRedirectError('Facebook token exchange failed.');
    $tokenData   = json_decode($tokenResp, true);
    $accessToken = $tokenData['access_token'] ?? '';
    if (!$accessToken) oauthRedirectError('Facebook token invalid.');

    /* Get user info */
    $userUrl  = 'https://graph.facebook.com/me?fields=id,name,email,picture.width(200)&access_token=' . urlencode($accessToken);
    $userResp = file_get_contents($userUrl);
    if (!$userResp) oauthRedirectError('Facebook user info failed.');
    $user = json_decode($userResp, true);

    $fbId      = $user['id']                            ?? '';
    $name      = $user['name']                          ?? 'Facebook User';
    $email     = $user['email']                         ?? '';
    $avatarUrl = $user['picture']['data']['url']        ?? '';

    if (!$fbId) oauthRedirectError('Facebook user ID not received.');

    $res = memberOAuthLogin('facebook', $fbId, $name, $email, $avatarUrl);
    if (isset($res['error'])) oauthRedirectError($res['error']);

    header('Location: ' . SITE_URL . 'member/?welcome=facebook');
    exit;
}

oauthRedirectError($_t('अज्ञात OAuth provider।', 'Unknown OAuth provider.'));
