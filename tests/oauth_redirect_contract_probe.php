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
    'native_custom_scheme_exact' => OAuth::matchesRegisteredRedirect(
        ['github-app://oauth/callback'],
        'github-app://oauth/callback',
    ),
    'native_custom_scheme_mismatch' => OAuth::matchesRegisteredRedirect(
        ['github-app://oauth/callback'],
        'github-app://oauth/other',
    ),
    'native_custom_scheme_accepted_generically' => OAuth::validRedirect('my-editor+oauth://login/callback'),
    'native_private_use_scheme_accepted' => OAuth::matchesRegisteredRedirect(
        ['com.example.desktop:/oauth/callback'],
        'com.example.desktop:/oauth/callback',
    ),
    'javascript_scheme_rejected' => OAuth::validRedirect('javascript://attacker.example/callback'),
    'userinfo_rejected' => OAuth::validRedirect('https://user:pass@client.example/callback'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
