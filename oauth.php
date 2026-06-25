<?php
/**
 * Member Portal — OAuth Callback Handler
 * Google + Facebook OAuth2 Flow
 */
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded


$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
$code     = (string)($_GET['code'] ?? '');
$state    = (string)($_GET['state'] ?? '');
$error_p  = (string)($_GET['error'] ?? '');
function oauthRedirectError($msg) {
    error_log('[oauth-error] ' . $msg . ' | provider=' . ($_GET['provider'] ?? 'unknown') . ' | ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $_SESSION['oauth_error'] = $msg;
    header('Location: ' . SITE_URL . 'member/login.php?tab=login');
    exit;
}

if (!in_array($provider, ['google', 'facebook'], true)) {
    oauthRedirectError(t('अज्ञात OAuth provider।', 'Unknown OAuth provider.'));
}
$code  = mb_substr(str_replace("\0", '', $code), 0, 4096, 'UTF-8');
$state = mb_substr(str_replace("\0", '', $state), 0, 512, 'UTF-8');


/**
 * oauthCurl — file_get_contents को सट्टा cURL use गर्छ।
 * HTTP error codes (4xx/5xx) detect गर्छ र false फर्काउँछ।
 * Returns: [string $body, int $httpCode] | [false, int $httpCode]
 */
function oauthCurl(string $url, array $postFields = [], array $headers = []): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($postFields) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlErr) {
        error_log('[oauth-curl-error] ' . $curlErr . ' url=' . $url);
        return [false, 0];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[oauth-http-error] HTTP ' . $httpCode . ' url=' . $url . ' body=' . substr((string)$body, 0, 300));
        return [false, $httpCode];
    }
    return [$body, $httpCode];
}

/* State CSRF check — state is ALWAYS required */
if ($error_p) {
    oauthRedirectError(t('OAuth रद्द भयो। पुनः प्रयास गर्नुहोस्।', 'OAuth cancelled. Please try again.'));
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

    if (!$clientId || !$clientSecret) oauthRedirectError(t('Google OAuth configure भएको छैन। Admin लाई भेट्नुहोस्।', 'Google OAuth is not configured. Please contact admin.'));

    /* Exchange code for token */
    [$tokenResp, ] = oauthCurl('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]);
    if ($tokenResp === false) oauthRedirectError('Google token exchange failed. Please try again.');
    $tokenData = json_decode($tokenResp, true);
    $accessToken = $tokenData['access_token'] ?? '';
    if (!$accessToken) oauthRedirectError('Google token invalid.');

    /* Get user info */
    [$userResp, ] = oauthCurl(
        'https://www.googleapis.com/oauth2/v2/userinfo',
        [],
        ['Authorization: Bearer ' . $accessToken]
    );
    if ($userResp === false) oauthRedirectError('Google user info fetch failed.');
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

    if (!$appId || !$appSecret) oauthRedirectError(t('Facebook OAuth configure भएको छैन। Admin लाई भेट्नुहोस्।', 'Facebook OAuth is not configured. Please contact admin.'));

    /* Exchange code for token — POST गरेर GET URL मा secret नराख्ने */
    [$tokenResp, ] = oauthCurl('https://graph.facebook.com/v18.0/oauth/access_token', [
        'client_id'     => $appId,
        'client_secret' => $appSecret,
        'redirect_uri'  => $redirectUri,
        'code'          => $code,
    ]);
    if ($tokenResp === false) oauthRedirectError('Facebook token exchange failed. Please try again.');
    $tokenData   = json_decode($tokenResp, true);
    $accessToken = $tokenData['access_token'] ?? '';
    if (!$accessToken) oauthRedirectError('Facebook token invalid.');

    /* Get user info */
    $userUrl  = 'https://graph.facebook.com/me?fields=id,name,email,picture.width(200)&access_token=' . urlencode($accessToken);
    [$userResp, ] = oauthCurl($userUrl);
    if ($userResp === false) oauthRedirectError('Facebook user info failed.');
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

oauthRedirectError(t('अज्ञात OAuth provider।', 'Unknown OAuth provider.'));
