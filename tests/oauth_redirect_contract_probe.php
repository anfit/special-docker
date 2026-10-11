<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Special\OAuth;

echo json_encode([
    'localhost_ephemeral_port' => OAuth::matchesRegisteredRedirect(
        ['http://localhost/callback'],
        'http://localhost:43123/callback',
    ),
    'ipv6_ephemeral_port' => OAuth::matchesRegisteredRedirect(
        ['http://[::1]/callback'],
        'http://[::1]:43123/callback',
    ),
    'loopback_path_mismatch' => OAuth::matchesRegisteredRedirect(
        ['http://localhost/callback'],
        'http://localhost:43123/other',
    ),
    'loopback_query_mismatch' => OAuth::matchesRegisteredRedirect(
        ['http://localhost/callback?client=one'],
        'http://localhost:43123/callback?client=two',
    ),
    'https_port_mismatch' => OAuth::matchesRegisteredRedirect(
        ['https://client.example/callback'],
        'https://client.example:444/callback',
    ),
    'https_exact' => OAuth::matchesRegisteredRedirect(
        ['https://client.example/callback'],
        'https://client.example/callback',
    ),
    'userinfo_rejected' => OAuth::validRedirect('https://user:pass@client.example/callback'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
