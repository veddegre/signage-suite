<?php
require_once __DIR__ . '/lib/camwall_lib.php';
camwall_stream_image((string)($_GET['cam'] ?? ''));
