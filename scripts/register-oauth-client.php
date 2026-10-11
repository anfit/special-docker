<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Special\OAuth;
use Special\SpecialException;

$options = getopt('', ['client-id:', 'name:', 'redirect-uri:', 'type::', 'secret::']);
$clientId = is_array($options) && is_string($options['client-id'] ?? null) ? $options['client-id'] : '';
$clientName = is_array($options) && is_string($options['name'] ?? null) ? $options['name'] : '';
$redirectUris = [];
if (is_array($options) && array_key_exists('redirect-uri', $options)) {
    $raw = $options['redirect-uri'];
    $redirectUris = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : (is_string($raw) ? [$raw] : []);
}
$clientType = is_array($options) && is_string($options['type'] ?? null) ? $options['type'] : 'public';
$clientSecret = is_array($options) && is_string($options['secret'] ?? null) ? $options['secret'] : null;
if ($clientSecret === null && $clientType === 'confidential') {
    $envSecret = getenv('SPECIAL_OAUTH_CLIENT_SECRET');
    $clientSecret = $envSecret === false ? null : $envSecret;
}

if ($clientId === '' || $clientName === '' || $redirectUris === []) {
    fwrite(STDERR, "Usage: php scripts/register-oauth-client.php --client-id <id> --name <name> --redirect-uri <uri> [--redirect-uri <uri>] [--type public|confidential]\n");
    exit(2);
}

try {
    $config = special_config();
    $oauth = new OAuth(special_store($config), new Special\Security(special_store($config), $config), $config);
    $client = $oauth->registerClient($clientId, $clientName, $redirectUris, $clientType, $clientSecret);
    fwrite(STDOUT, json_encode($client, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
} catch (SpecialException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Unable to register OAuth client: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}

