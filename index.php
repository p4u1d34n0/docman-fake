<?php

/**
 * Fake Docman API for testing.
 *
 * Mimics the two Docman endpoints:
 *   POST /token          — returns a fake access token
 *   POST /api/documents  — accepts a document payload, returns a fake GUID
 *
 * Run with: php -S 0.0.0.0:8089 index.php
 * Or as a Docker container (see Dockerfile alongside).
 *
 * Set DOCMAN_API_URL=http://docman-fake:8089 in your .env
 */
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Log every request for debugging
$body = file_get_contents('php://input');
$logLine = date('Y-m-d H:i:s') . " {$method} {$uri} " . strlen($body) . " bytes\n";
file_put_contents('php://stderr', $logLine);

if ($method === 'POST' && $uri === '/token') {
    // Token endpoint — return a fake bearer token
    echo json_encode([
        'access_token' => 'fake-docman-token-' . bin2hex(random_bytes(16)),
        'token_type' => 'bearer',
        'expires_in' => 3600,
    ]);
    exit;
}

if ($method === 'POST' && $uri === '/api/documents') {
    // Document upload endpoint — validate and return a fake GUID
    $payload = json_decode($body, true);

    if (empty($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty or invalid JSON payload']);
        exit;
    }

    $guid = 'FAKE-' . strtoupper(bin2hex(random_bytes(16)));
    $consultId = $payload['Document']['ExternalSystemId'] ?? 'unknown';
    $patient = ($payload['Patient']['FamilyName'] ?? '') . ', ' . ($payload['Patient']['GivenNames'] ?? '');

    file_put_contents('php://stderr', "  -> Accepted consult #{$consultId} for {$patient} => {$guid}\n");

    echo json_encode([
        'Guid' => $guid,
        'Status' => 'Accepted',
        'Message' => 'Document accepted (fake)',
    ]);
    exit;
}

// Anything else — 404
http_response_code(404);
echo json_encode(['error' => 'Not found: ' . $uri]);
