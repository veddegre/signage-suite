<?php
/**
 * Legacy URL — playlists used to reference index.php for the weather board.
 * Preserve query string (noticker, screen, theme, etc.) for iframe loads.
 */
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$dest = 'weather.php' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $dest, true, 301);
exit;
