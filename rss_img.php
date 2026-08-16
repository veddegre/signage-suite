<?php
require_once __DIR__ . '/lib/rss_image_lib.php';
rss_image_stream((string)($_GET['i'] ?? ''));
