<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Security.php';

use Special\Security;
use Special\SpecialException;

$options = getopt('', ['username:']);
$username = is_array($options) && isset($options['username']) && is_string($options['username'])
    ? $options['username']
    : '';
$password = getenv('SPECIAL_BOOTSTRAP_ADMIN_PASSWORD');
$passwordHash = getenv('SPECIAL_BOOTSTRAP_ADMIN_PASSWORD_HASH');
$hasPassword = is_string($password) && $password !== '';
$hasPasswordHash = is_string($passwordHash) && $passwordHash !== '';

if ($username === '' || $hasPassword === $hasPasswordHash) {
    fwrite(STDERR, "Usage: set exactly one of SPECIAL_BOOTSTRAP_ADMIN_PASSWORD or SPECIAL_BOOTSTRAP_ADMIN_PASSWORD_HASH before running php scripts/bootstrap-admin.php --username <username>\n");
    exit(2);
}

try {
    $config = special_config();
    $security = new Security(special_store($config), $config);
    $userId = $hasPassword
        ? $security->bootstrapFirstAdmin($username, (string) $password, special_request_id())
        : $security->bootstrapFirstAdminWithHash($username, (string) $passwordHash, special_request_id());
    fwrite(STDOUT, "Created first administrator with id {$userId}.\n");
} catch (SpecialException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Unable to bootstrap the administrator: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
