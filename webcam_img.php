<?php
require_once __DIR__ . '/lib/webcam_lib.php';
webcam_stream_image((string)($_GET['cam'] ?? ''));
