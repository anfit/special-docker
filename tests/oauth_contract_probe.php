<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Special\OAuth;

$reflection = new ReflectionClass(OAuth::class);
$oauth = $reflection->newInstanceWithoutConstructor();
$configProperty = $reflection->getProperty('config');
$configProperty->setValue($oauth, [
    'app_url' => 'https://special.example.test',
    'mcp_resource' => 'https://special.example.test/mcp',
]);
$scopeMethod = $reflection->getMethod('normalizeScopes');
$scopeMethod->setAccessible(true);
echo json_encode([
    'authorization_server' => $oauth->metadata(),
    'protected_resource' => $oauth->protectedResourceMetadata(),
    'default_scope' => OAuth::DEFAULT_SCOPE,
    'mixed_legacy_scope' => $scopeMethod->invoke($oauth, 'mcp:tools model.read'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
