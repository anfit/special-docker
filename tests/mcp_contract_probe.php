<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Special\McpAdapter;
use Special\Principal;
use Special\Security;
use function Special\special_mcp_oauth_challenge;

$reflection = new ReflectionClass(McpAdapter::class);
$adapter = $reflection->newInstanceWithoutConstructor();
$config = [
    'mcp_max_page_size' => 50,
    'mcp_discovery_version' => '2026-07-28',
    'mcp_protocol_version' => '2025-06-18',
];
$configProperty = $reflection->getProperty('config');
$configProperty->setValue($adapter, $config);
$security = (new ReflectionClass(Security::class))->newInstanceWithoutConstructor();
$securityProperty = $reflection->getProperty('security');
$securityProperty->setValue($adapter, $security);
$method = $reflection->getMethod('visibleTools');
$method->setAccessible(true);
$tools = $method->invoke($adapter, new Principal(1, 'probe', 'admin'));
$writeTools = $method->invoke($adapter, new Principal(2, 'write-only', 'editor', ['model.write'], 'mcp', 'client'));
$handle = $reflection->getMethod('handle');
$discovery = $handle->invoke($adapter, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover'], null);
$readPrincipal = new Principal(3, 'read-client', 'editor', ['model.read'], 'mcp', 'client');
$initialize = $handle->invoke($adapter, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize'], $readPrincipal);
$ping = $handle->invoke($adapter, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], $readPrincipal);
$unauthenticatedInitialize = $handle->invoke($adapter, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'initialize'], null);
$unauthenticatedInitializeStatus = $adapter->httpStatus();
$unauthenticatedNotification = $handle->invoke($adapter, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], null);
$unauthenticatedNotificationStatus = $adapter->httpStatus();
$writeOnlyPrincipal = new Principal(4, 'write-client', 'editor', ['model.write'], 'mcp', 'client');
$writeOnlyInitialize = $handle->invoke($adapter, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'initialize'], $writeOnlyPrincipal);
$writeOnlyInitializeStatus = $adapter->httpStatus();
$writeOnlyNotification = $handle->invoke($adapter, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $writeOnlyPrincipal);
$writeOnlyNotificationStatus = $adapter->httpStatus();
echo json_encode([
    'tools' => $tools,
    'write_tools' => $writeTools,
    'discovery' => $discovery,
    'initialize' => $initialize,
    'ping' => $ping,
    'unauthenticated_initialize' => $unauthenticatedInitialize,
    'unauthenticated_initialize_status' => $unauthenticatedInitializeStatus,
    'unauthenticated_notification' => $unauthenticatedNotification,
    'unauthenticated_notification_status' => $unauthenticatedNotificationStatus,
    'write_only_initialize' => $writeOnlyInitialize,
    'write_only_initialize_status' => $writeOnlyInitializeStatus,
    'write_only_notification' => $writeOnlyNotification,
    'write_only_notification_status' => $writeOnlyNotificationStatus,
    'challenge_missing' => special_mcp_oauth_challenge(['app_url' => 'https://special.example.test']),
    'challenge_invalid' => special_mcp_oauth_challenge(['app_url' => 'https://special.example.test'], ['oauth_error' => 'invalid_token']),
    'challenge_insufficient' => special_mcp_oauth_challenge(
        ['app_url' => 'https://special.example.test'],
        ['oauth_error' => 'insufficient_scope', 'required_scope' => 'model.write'],
        403,
    ),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
