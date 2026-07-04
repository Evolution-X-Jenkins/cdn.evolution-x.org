<?php
/**
 * Discord Bot Command API Module
 *
 * Commands:
 * - status: retrieve rate-limit status for an identity
 * - unblock: clear active block for an identity
 */

require_once __DIR__ . '/../setup/config.php';
require_once __DIR__ . '/../core/rate_limit.php';

function handleDiscordBotApi($method, $pathParts) {
    if (!in_array($method, ['GET', 'POST'], true)) {
        return [
            'status' => 'error',
            'APICode' => 'D-0003',
            'message' => 'Method not allowed. Use GET or POST.'
        ];
    }

    $payload = [];
    if ($method === 'POST') {
        $rawPayload = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            return [
                'status' => 'error',
                'APICode' => 'D-0002',
                'message' => 'Invalid JSON payload'
            ];
        }
        $payload = $decoded;
    } else {
        $payload = $_GET;
    }

    $command = strtolower(trim((string)($payload['command'] ?? '')));
    if ($command === '' && $method === 'GET') {
        $command = 'status';
    }

    $identityType = strtolower(trim((string)($payload['identityType'] ?? '')));
    $identityValue = trim((string)($payload['identityValue'] ?? ''));

    if ($command === '' || $identityType === '' || $identityValue === '') {
        return [
            'status' => 'error',
            'APICode' => 'D-0002',
            'message' => 'command, identityType, and identityValue are required'
        ];
    }

    if (!in_array($identityType, ['ip', 'user'], true)) {
        return [
            'status' => 'error',
            'APICode' => 'D-0002',
            'message' => 'identityType must be ip or user'
        ];
    }

    // Status retrieval is intentionally public for Discord bot read-only checks.
    if ($command === 'unblock' && !isDiscordBotExternalAuthorized()) {
        return [
            'status' => 'error',
            'APICode' => 'D-0001',
            'message' => 'Unauthorized'
        ];
    }

    try {
        if ($command === 'status') {
            $status = get_rate_limit_identity_status($identityType, $identityValue);
            return [
                'success' => true,
                'data' => $status
            ];
        }

        if ($command === 'unblock') {
            $result = unblock_rate_limit_identity($identityType, $identityValue);
            return [
                'success' => true,
                'data' => $result
            ];
        }

        return [
            'status' => 'error',
            'APICode' => 'D-0002',
            'message' => 'Unsupported command. Use status or unblock.'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'APICode' => 'D-0004',
            'message' => 'Discord bot command failed: ' . $e->getMessage()
        ];
    }
}

function isDiscordBotExternalAuthorized() {
    $configuredIdentifier = trim((string)(getenv('DISCORD_BOT_EXTERNAL_IDENTIFIER') ?: ''));
    $configuredPassword = trim((string)(getenv('DISCORD_BOT_EXTERNAL_PASSWORD') ?: ''));

    if ($configuredIdentifier === '' || $configuredPassword === '') {
        return false;
    }

    $incomingIdentifier = trim((string)(
        $_SERVER['HTTP_X_EXTERNAL_IDENTIFIER']
        ?? $_SERVER['HTTP_EXTERNAL_IDENTIFIER']
        ?? $_SERVER['HTTP_X_API_ID']
        ?? ''
    ));

    $incomingSecret = trim((string)(
        $_SERVER['HTTP_X_EXTERNAL_SECRET']
        ?? $_SERVER['HTTP_EXTERNAL_SECRET']
        ?? $_SERVER['HTTP_X_API_SECRET']
        ?? ''
    ));

    if ($incomingIdentifier === '' || $incomingSecret === '') {
        return false;
    }

    if (!hash_equals($configuredIdentifier, $incomingIdentifier)) {
        return false;
    }

    $todayUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $candidateDates = [
        $todayUtc->format('Y-m-d'),
        $todayUtc->modify('-1 day')->format('Y-m-d'),
    ];

    foreach ($candidateDates as $dateStr) {
        $expected = hash_hmac('sha256', $configuredIdentifier . '-' . $dateStr, $configuredPassword);
        if (hash_equals($expected, $incomingSecret)) {
            return true;
        }
    }

    return false;
}
