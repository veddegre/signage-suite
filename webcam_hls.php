<?php
require_once __DIR__ . '/lib/webcam_lib.php';
webcam_hls_serve((string)($_GET['cam'] ?? ''));
