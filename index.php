<?php
/**
 * Legacy URL — playlists used to reference index.php for the weather board.
 * Preserve query string (noticker, screen, theme, etc.) for iframe loads.
 *
 * SSO: Authentik (and some IdP app tiles) sometimes redirect here instead of admin.php.
 * Forward OIDC start/callback to admin.php so sign-in completes in the admin UI.
 */
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$sso = (string)($_GET['sso'] ?? '');

if ($sso === 'start' || $sso === 'callback') {
    header('Location: admin.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}

// OAuth authorization response aimed at index.php (mis-registered redirect URI).
if (isset($_GET['code']) && (string)$_GET['code'] !== '' && isset($_GET['state'])) {
    $destQs = $sso === 'callback' ? $qs : ('sso=callback' . ($qs !== '' ? '&' . $qs : ''));
    header('Location: admin.php?' . $destQs, true, 302);
    exit;
}

if (isset($_GET['error']) && ((string)($_GET['state'] ?? '') !== '' || (string)($_GET['error_description'] ?? '') !== '')) {
    $destQs = 'sso=callback' . ($qs !== '' ? '&' . $qs : '');
    header('Location: admin.php?' . $destQs, true, 302);
    exit;
}

$dest = 'weather.php' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $dest, true, 301);
exit;
