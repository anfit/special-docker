<?php

declare(strict_types=1);

require_once __DIR__ . '/Domain.php';
require_once __DIR__ . '/Storage/Store.php';
require_once __DIR__ . '/Storage/MemoryStore.php';
require_once __DIR__ . '/Storage/DynamoSchema.php';
require_once __DIR__ . '/RememberMe.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/SpecService.php';
require_once __DIR__ . '/OAuth.php';
require_once __DIR__ . '/mcp.php';

use Special\SpecialException;
use Special\RememberMe;
use Special\Storage\Store;

/** @return array<string, mixed> */
function special_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $environmentValue = getenv('SPECIAL_ENV');
    if ($environmentValue === false || $environmentValue === '') {
        $environmentValue = getenv('SPECIAL_APP_ENV');
    }
    $environment = match (strtolower((string) ($environmentValue ?: 'development'))) {
        'dev', 'development' => 'development',
        'prod', 'production' => 'production',
        default => throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_ENV must be dev or prod.', 500),
    };

    $appKey = getenv('SPECIAL_APP_KEY');
    if ($appKey === false || $appKey === '') {
        $appKey = $environment === 'production'
            ? ''
            : 'special-development-key-change-before-production';
    }

    $store = strtolower((string) (getenv('SPECIAL_STORE') ?: 'dynamodb'));
    if (!in_array($store, ['dynamodb', 'memory'], true)) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_STORE must be dynamodb or memory.', 500);
    }
    $region = (string) (getenv('SPECIAL_AWS_REGION') ?: getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION') ?: '');
    $endpoint = (string) (getenv('SPECIAL_DYNAMODB_ENDPOINT') ?: '');
    if ($endpoint !== '' && filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_DYNAMODB_ENDPOINT must be a URL.', 500);
    }

    $appUrlValue = getenv('SPECIAL_PUBLIC_BASE_URL');
    if ($appUrlValue === false || $appUrlValue === '') {
        $appUrlValue = getenv('SPECIAL_APP_URL');
    }
    $appUrl = rtrim((string) ($appUrlValue ?: 'http://127.0.0.1:8080'), '/');
    $urlParts = parse_url($appUrl);
    if (!is_array($urlParts)
        || !isset($urlParts['scheme'], $urlParts['host'])
        || !in_array(strtolower((string) $urlParts['scheme']), ['http', 'https'], true)
        || array_key_exists('user', $urlParts)
        || array_key_exists('pass', $urlParts)
        || array_key_exists('query', $urlParts)
        || array_key_exists('fragment', $urlParts)
        || (($urlParts['path'] ?? '') !== '' && ($urlParts['path'] ?? '') !== '/')) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_PUBLIC_BASE_URL must be an absolute HTTP(S) origin.', 500);
    }

    $sessionPath = (string) (getenv('SPECIAL_SESSION_PATH') ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'special-sessions'));
    if ($sessionPath === '') {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_SESSION_PATH must not be empty.', 500);
    }
    // Pre-TLS escape hatch: lets a production deployment run over plain HTTP (for example behind an SSM
    // port-forward) until the corporate load balancer / proxy that terminates TLS is attached.
    $insecureHttp = filter_var((string) (getenv('SPECIAL_INSECURE_HTTP') ?: '0'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($insecureHttp === null) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_INSECURE_HTTP must be boolean.', 500);
    }
    $sessionSecureValue = getenv('SPECIAL_SESSION_SECURE');
    if ($sessionSecureValue === false || $sessionSecureValue === '') {
        $sessionSecureValue = ($environment === 'production' && !$insecureHttp) ? '1' : '0';
    }
    $sessionSecure = filter_var($sessionSecureValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($sessionSecure === null) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_SESSION_SECURE must be boolean.', 500);
    }
    $trustProxy = filter_var((string) (getenv('SPECIAL_TRUST_PROXY') ?: '0'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($trustProxy === null) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_TRUST_PROXY must be boolean.', 500);
    }
    $setupToken = (string) (getenv('SPECIAL_SETUP_TOKEN') ?: '');
    if ($setupToken !== '' && strlen($setupToken) < 20) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_SETUP_TOKEN must contain at least 20 characters.', 500);
    }
    $rememberDays = RememberMe::lifetimeDays();
    $mcpResponseBytes = max(8192, min(2_000_000, (int) (getenv('SPECIAL_MCP_RESPONSE_BYTES') ?: 64_000)));
    $configuredPageSize = max(1, min(100, (int) (getenv('SPECIAL_MCP_MAX_PAGE_SIZE') ?: 50)));
    $mcpMaxPageSize = min($configuredPageSize, max(1, intdiv($mcpResponseBytes, 2400)));
    $config = [
        'environment' => $environment,
        'app_url' => $appUrl,
        'mcp_resource' => $appUrl . '/mcp',
        'bind' => (string) (getenv('SPECIAL_BIND') ?: '127.0.0.1:8080'),
        'mcp_protocol_version' => (string) (getenv('SPECIAL_MCP_PROTOCOL_VERSION') ?: '2025-06-18'),
        'mcp_discovery_version' => (string) (getenv('SPECIAL_MCP_DISCOVERY_VERSION') ?: '2026-07-28'),
        'mcp_server_version' => (string) (getenv('SPECIAL_MCP_SERVER_VERSION') ?: '1.0.0'),
        'app_key' => $appKey,
        'setup_token' => $setupToken,
        'version' => (string) (getenv('SPECIAL_VERSION') ?: 'dev'),
        'commit' => (string) (getenv('SPECIAL_COMMIT') ?: 'unknown'),
        'store' => $store,
        'dynamodb_table' => (string) (getenv('SPECIAL_DYNAMODB_TABLE') ?: 'special'),
        'aws_region' => $region,
        'dynamodb_endpoint' => $endpoint,
        'memory_store_file' => (string) (getenv('SPECIAL_MEMORY_STORE_FILE') ?: ''),
        'session_name' => (string) (getenv('SPECIAL_SESSION_NAME') ?: 'special_session'),
        'session_path' => $sessionPath,
        'session_secure' => $sessionSecure,
        'trust_proxy' => $trustProxy,
        'trusted_proxy_hops' => max(1, min(5, (int) (getenv('SPECIAL_TRUSTED_PROXY_HOPS') ?: 1))),
        'insecure_http' => $insecureHttp,
        'remember_days' => $rememberDays,
        'mcp_request_bytes' => max(16_384, min(4_000_000, (int) (getenv('SPECIAL_MCP_REQUEST_BYTES') ?: 2_000_000))),
        'mcp_response_bytes' => $mcpResponseBytes,
        'mcp_max_page_size' => $mcpMaxPageSize,
        'oauth_access_ttl' => max(60, min(3600, (int) (getenv('SPECIAL_OAUTH_ACCESS_TTL') ?: 900))),
        'oauth_refresh_ttl' => max(3600, min(2_592_000, (int) (getenv('SPECIAL_OAUTH_REFRESH_TTL') ?: 2_592_000))),
        'oauth_code_ttl' => max(60, min(900, (int) (getenv('SPECIAL_OAUTH_CODE_TTL') ?: 300))),
        'login_max_attempts' => max(1, min(20, (int) (getenv('SPECIAL_LOGIN_MAX_ATTEMPTS') ?: 5))),
        'login_window_seconds' => max(60, min(3600, (int) (getenv('SPECIAL_LOGIN_WINDOW_SECONDS') ?: 300))),
        'login_block_seconds' => max(60, min(86_400, (int) (getenv('SPECIAL_LOGIN_BLOCK_SECONDS') ?: 900))),
    ];

    if ($config['store'] === 'dynamodb' && $config['dynamodb_table'] === '') {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_DYNAMODB_TABLE must not be empty.', 500);
    }

    if ($environment === 'production') {
        if (strlen((string) $config['app_key']) < 32) {
            throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_APP_KEY must contain at least 32 bytes in production.', 500);
        }
        if ($config['store'] !== 'dynamodb') {
            throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_STORE must be dynamodb in production.', 500);
        }
        if ($config['aws_region'] === '') {
            throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_AWS_REGION is required in production.', 500);
        }
        if ($config['dynamodb_endpoint'] !== '') {
            throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_DYNAMODB_ENDPOINT is for local development only.', 500);
        }
        if (!$insecureHttp && (bool) $config['session_secure'] !== true) {
            throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_SESSION_SECURE must be enabled in production.', 500);
        }
        if (!$insecureHttp && strtolower((string) $urlParts['scheme']) !== 'https') {
            throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_PUBLIC_BASE_URL must use HTTPS in production (set SPECIAL_INSECURE_HTTP=1 only until TLS is attached).', 500);
        }
    }

    if ($config['session_name'] === '' || preg_match('/^[A-Za-z0-9_]+$/', $config['session_name']) !== 1) {
        throw new SpecialException('CONFIGURATION_ERROR', 'SPECIAL_SESSION_NAME is invalid.', 500);
    }

    return $config;
}

/**
 * The shared persistence boundary for this process.
 *
 * `memory` is only for tests and throw-away local runs; `dynamodb` talks to AWS (or to DynamoDB Local
 * when SPECIAL_DYNAMODB_ENDPOINT is set). The AWS SDK is loaded lazily so tests do not need it.
 */
function special_store(?array $config = null): Store
{
    static $stores = [];
    $config ??= special_config();
    $key = hash('sha256', json_encode([$config['store'], $config['dynamodb_table'], $config['aws_region'], $config['dynamodb_endpoint'], $config['memory_store_file']], JSON_THROW_ON_ERROR));
    if (isset($stores[$key])) {
        return $stores[$key];
    }

    if ($config['store'] === 'memory') {
        return $stores[$key] = new \Special\Storage\MemoryStore($config['memory_store_file'] !== '' ? $config['memory_store_file'] : null);
    }

    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new SpecialException('CONFIGURATION_ERROR', 'AWS SDK is not installed; run composer install.', 500);
    }
    require_once $autoload;
    require_once __DIR__ . '/Storage/DynamoStore.php';
    require_once __DIR__ . '/Storage/CredentialCache.php';

    $arguments = [
        'version' => '2012-08-10',
        'region' => $config['aws_region'] !== '' ? $config['aws_region'] : 'us-east-1',
        'retries' => 3,
        'http' => ['connect_timeout' => 2, 'timeout' => 8],
    ];
    if ($config['dynamodb_endpoint'] !== '') {
        // DynamoDB Local accepts any credentials; never fall through to the instance-metadata lookup there.
        $arguments['endpoint'] = $config['dynamodb_endpoint'];
        $arguments['credentials'] = ['key' => getenv('AWS_ACCESS_KEY_ID') ?: 'local', 'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: 'local'];
    } elseif (!getenv('AWS_ACCESS_KEY_ID')) {
        // EC2 instance profile: cache the short-lived credentials on disk because every PHP request is a fresh process.
        $arguments['credentials'] = \Aws\Credentials\CredentialProvider::memoize(
            \Aws\Credentials\CredentialProvider::chain(
                \Aws\Credentials\CredentialProvider::env(),
                \Aws\Credentials\CredentialProvider::ecsCredentials(),
                \Aws\Credentials\CredentialProvider::cache(
                    \Aws\Credentials\CredentialProvider::instanceProfile(),
                    new \Special\Storage\CredentialCache(sys_get_temp_dir() . '/special-aws-credentials.cache'),
                    'special_instance_profile',
                ),
            ),
        );
    }

    return $stores[$key] = new \Special\Storage\DynamoStore(new \Aws\DynamoDb\DynamoDbClient($arguments), $config['dynamodb_table']);
}

function special_request_id(): string
{
    $candidate = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    if (is_string($candidate) && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $candidate) === 1) {
        return $candidate;
    }

    return bin2hex(random_bytes(16));
}

/**
 * Client address for throttling and audit. With SPECIAL_TRUST_PROXY=1 the entry SPECIAL_TRUSTED_PROXY_HOPS places
 * from the right of `X-Forwarded-For` is used: 1 for a single TLS-terminating proxy/load balancer (which appends
 * the address it saw), 2 when a second trusted proxy sits in front of it, and so on. Entries further left are
 * client-supplied and ignored.
 */
function special_client_ip(array $config): string
{
    if (($config['trust_proxy'] ?? false) === true) {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            $index = max(0, count($parts) - max(1, (int) ($config['trusted_proxy_hops'] ?? 1)));
            $candidate = (string) ($parts[$index] ?? '');
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}
