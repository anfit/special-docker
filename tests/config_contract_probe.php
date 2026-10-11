<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$config = special_config();
echo json_encode([
    'environment' => $config['environment'],
    'app_url' => $config['app_url'],
    'mcp_resource' => $config['mcp_resource'],
    'store' => $config['store'],
    'dynamodb_table' => $config['dynamodb_table'],
    'aws_region' => $config['aws_region'],
    'session_secure' => $config['session_secure'],
    'trust_proxy' => $config['trust_proxy'],
    'remember_days' => $config['remember_days'],
    'insecure_http' => $config['insecure_http'],
    'trusted_proxy_hops' => $config['trusted_proxy_hops'],
    'version' => $config['version'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
