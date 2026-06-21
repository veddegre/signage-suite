<?php
/**
 * Legacy URL — family.php was renamed to calendar.php.
 */
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$target = 'calendar.php' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $target, true, 301);
exit;
