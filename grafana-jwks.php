<?php
/**
 * Public JWKS endpoint for Grafana Cloud JWT verification (RS256).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/grafana_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$doc = grafana_jwks_document();
if ($doc === null) {
    http_response_code(404);
    echo json_encode(['error' => 'JWKS not configured — set JWT algorithm RS256 and private key in admin'], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode($doc, JSON_UNESCAPED_SLASHES);
