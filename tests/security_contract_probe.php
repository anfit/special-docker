<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Special\Principal;

$principals = [
    'viewer_web' => new Principal(1, 'viewer', 'viewer'),
    'editor_web' => new Principal(2, 'editor', 'editor'),
    'admin_web' => new Principal(3, 'admin', 'admin'),
    'editor_read_token' => new Principal(4, 'editor', 'editor', ['model.read'], 'mcp', 'client'),
    'editor_write_token' => new Principal(5, 'editor', 'editor', ['model.write'], 'mcp', 'client'),
    'viewer_legacy_token' => new Principal(6, 'viewer', 'viewer', ['mcp:tools'], 'mcp', 'client'),
];
$permissions = ['spec.read', 'spec.write', 'users.manage'];
$result = [];
foreach ($principals as $name => $principal) {
    $result[$name] = [];
    foreach ($permissions as $permission) {
        $result[$name][$permission] = $principal->can($permission);
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
