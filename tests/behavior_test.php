<?php

declare(strict_types=1);

/**
 * Behaviour suite for the storage-backed services.
 *
 *   php tests/behavior_test.php            # in-memory store (default)
 *   php tests/behavior_test.php dynamodb   # DynamoDB Local; needs vendor/ and SPECIAL_DYNAMODB_ENDPOINT
 *
 * The same assertions run against every store, so the in-memory reference and the DynamoDB
 * implementation are held to one contract. In dynamodb mode a throw-away table is created and dropped.
 */

putenv('SPECIAL_ENV=development');
putenv('SPECIAL_APP_KEY=behavior-test-key-with-at-least-32-characters');
putenv('SPECIAL_PUBLIC_BASE_URL=https://special.example.test');

require dirname(__DIR__) . '/src/bootstrap.php';

use Special\McpAdapter;
use Special\OAuth;
use Special\Principal;
use Special\Security;
use Special\SpecialException;
use Special\SpecService;
use Special\Storage\DynamoSchema;
use Special\Storage\Store;

$mode = $argv[1] ?? 'memory';
$tests = [];
$failures = 0;
$passed = 0;

function test(string $name, callable $body): void
{
    global $tests;
    $tests[$name] = $body;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new AssertionError($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new AssertionError($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function throws(string $code, callable $body, string $message = ''): SpecialException
{
    try {
        $body();
    } catch (SpecialException $exception) {
        same($code, $exception->errorCode(), $message !== '' ? $message : 'wrong error code: ' . $exception->getMessage());
        return $exception;
    }
    throw new AssertionError(($message !== '' ? $message : 'expected ' . $code) . ' (nothing thrown)');
}

final class Env
{
    public Security $security;
    public SpecService $specs;
    public OAuth $oauth;
    /** @var array<string, mixed> */
    public array $config;
    private int $sequence = 0;

    public function __construct(public Store $store)
    {
        $this->config = [
            'app_key' => 'behavior-test-key-with-at-least-32-characters',
            'app_url' => 'https://special.example.test',
            'mcp_resource' => 'https://special.example.test/mcp',
            'mcp_max_page_size' => 50,
            'mcp_response_bytes' => 64_000,
            'mcp_protocol_version' => '2025-06-18',
            'mcp_discovery_version' => '2026-07-28',
            'oauth_access_ttl' => 900,
            'oauth_refresh_ttl' => 2_592_000,
            'oauth_code_ttl' => 300,
            'login_max_attempts' => 3,
            'login_window_seconds' => 300,
            'login_block_seconds' => 900,
        ];
        $this->security = new Security($store, $this->config);
        $this->specs = new SpecService($store, $this->security, $this->config, 'req-test');
        $this->oauth = new OAuth($store, $this->security, $this->config);
    }

    public function name(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(3)) . ++$this->sequence;
    }

    /** First call creates the administrator; later calls reuse it. */
    public function admin(): Principal
    {
        static $admin = null;
        if ($admin === null || $admin['store'] !== $this->store) {
            $username = $this->name('admin');
            $id = $this->security->bootstrapFirstAdmin($username, 'correct horse battery staple', 'req-test');
            $admin = ['store' => $this->store, 'principal' => new Principal($id, $username, 'admin')];
        }

        return $admin['principal'];
    }

    public function user(string $role = 'editor'): Principal
    {
        $username = $this->name('user') . '@example.test';
        $created = $this->security->createUser($this->admin(), $username, 'a long enough password', $role, 'req-test');

        return new Principal($created['id'], $username, $role);
    }

    /** @param array<string, mixed> $overrides */
    public function spec(Principal $owner, array $overrides = []): array
    {
        return $this->specs->createSpec($owner, $overrides + ['title' => 'Spec ' . $this->name('t'), 'project_name' => 'Alpha']);
    }
}

// ------------------------------------------------------------------ users & auth

test('bootstrap closes after the first administrator', function (Env $env): void {
    $env->admin();
    throws('CONFLICT', fn () => $env->security->bootstrapFirstAdmin('second-admin', 'correct horse battery staple'));
});

test('usernames are unique case-insensitively', function (Env $env): void {
    $name = $env->name('dupe') . '@example.test';
    $env->security->createUser($env->admin(), $name, 'a long enough password', 'viewer');
    throws('INVALID_ARGUMENT', fn () => $env->security->createUser($env->admin(), strtoupper($name), 'a long enough password', 'viewer'));
});

test('authenticate accepts the right password and rejects the wrong one', function (Env $env): void {
    $user = $env->user();
    $principal = $env->security->authenticate($user->username, 'a long enough password', '203.0.113.1');
    same($user->id, $principal->id, 'authenticated id');
    throws('UNAUTHENTICATED', fn () => $env->security->authenticate($user->username, 'wrong password!!', '203.0.113.1'));
    throws('UNAUTHENTICATED', fn () => $env->security->authenticate('nobody@example.test', 'whatever password', '203.0.113.1'));
    $stored = $env->store->getUser($user->id);
    check($stored['last_login_at'] !== null, 'last_login_at recorded');
});

test('repeated failures lock the account for that address', function (Env $env): void {
    $user = $env->user();
    for ($i = 0; $i < 3; $i++) {
        throws('UNAUTHENTICATED', fn () => $env->security->authenticate($user->username, 'wrong password!!', '198.51.100.9'));
    }
    throws('UNAUTHENTICATED', fn () => $env->security->authenticate($user->username, 'a long enough password', '198.51.100.9'), 'blocked even with the right password');
    $env->security->authenticate($user->username, 'a long enough password', '198.51.100.10');
});

test('the final enabled administrator cannot be demoted or disabled', function (Env $env): void {
    $admin = $env->admin();
    throws('CONFLICT', fn () => $env->security->changeRole($admin, $admin->id, 'viewer'));
    throws('CONFLICT', fn () => $env->security->setEnabled($admin, $admin->id, false));
    $second = $env->user('viewer');
    $env->security->changeRole($admin, $second->id, 'admin');
    $env->security->changeRole($admin, $second->id, 'editor');
    $env->security->changeRole($admin, $second->id, 'admin');
    $env->security->setEnabled($admin, $second->id, false);
    $env->security->setEnabled($admin, $second->id, true);
});

test('disabling a user revokes their tokens and remember-me logins', function (Env $env): void {
    $user = $env->user();
    $client = $env->name('c');
    $env->oauth->registerClient($client, 'Test', ['https://client.example.test/cb']);
    $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $tokens = oauth_tokens($env, $user, $client, $verifier);
    check($env->security->principalFromBearer($tokens['access_token']) !== null, 'token works before disable');
    $env->store->putRemember(['selector' => bin2hex(random_bytes(16)), 'user_id' => $user->id, 'token_hash' => hash('sha256', 'x'), 'expires_at' => special_far(), 'created_at' => Special\special_now(), 'last_used_at' => null]);
    $env->security->setEnabled($env->admin(), $user->id, false);
    same(null, $env->security->principalFromBearer($tokens['access_token']), 'token dead after disable');
    $env->security->setEnabled($env->admin(), $user->id, true);
    same(null, $env->security->principalFromBearer($tokens['access_token']), 'token stays dead after re-enable');
});

test('changing a password revokes credentials and allows the new password', function (Env $env): void {
    $user = $env->user();
    $env->security->changePassword($user, $user->id, 'a brand new password');
    $env->security->authenticate($user->username, 'a brand new password', '192.0.2.5');
    throws('FORBIDDEN', fn () => $env->security->changePassword($user, $env->admin()->id, 'another new password'));
});

// ------------------------------------------------------------------ specs

test('specs are public by default and private only when asked', function (Env $env): void {
    $owner = $env->user();
    $public = $env->spec($owner);
    $private = $env->spec($owner, ['visibility' => 'private']);
    same('public', $public['visibility'], 'default visibility');
    same('private', $private['visibility'], 'explicit private');
    same(1, $public['revision'], 'revision starts at 1');
    same($owner->username, $public['author'], 'author resolved');
});

test('everyone signed in sees public specs; private ones only for the author', function (Env $env): void {
    $alice = $env->user();
    $bob = $env->user();
    $shared = $env->spec($alice, ['title' => 'Alice public']);
    $secret = $env->spec($alice, ['title' => 'Alice private', 'visibility' => 'private']);

    $bobTitles = array_column($env->specs->listSpecs($bob, [], 50)['items'], 'title');
    check(in_array('Alice public', $bobTitles, true), 'bob sees alice public');
    check(!in_array('Alice private', $bobTitles, true), 'bob does not see alice private');
    $aliceTitles = array_column($env->specs->listSpecs($alice, [], 50)['items'], 'title');
    check(in_array('Alice private', $aliceTitles, true), 'alice sees her private');

    same('Alice public', $env->specs->getSpec($bob, $shared['id'])['title'], 'bob reads public');
    throws('NOT_FOUND', fn () => $env->specs->getSpec($bob, $secret['id']), 'private hidden as not found');
    throws('NOT_FOUND', fn () => $env->specs->updateSpec($bob, $secret['id'], ['title' => 'x']));
});

test('only the author may change or delete a spec', function (Env $env): void {
    $alice = $env->user();
    $bob = $env->user();
    $spec = $env->spec($alice);
    throws('FORBIDDEN', fn () => $env->specs->updateSpec($bob, $spec['id'], ['title' => 'hijack']));
    throws('FORBIDDEN', fn () => $env->specs->deleteSpec($bob, $spec['id']));
    same('Alice', 'Alice', 'sanity');
    same($spec['title'], $env->specs->getSpec($alice, $spec['id'])['title'], 'untouched');
});

test('viewers can read but not write', function (Env $env): void {
    $viewer = $env->user('viewer');
    throws('FORBIDDEN', fn () => $env->specs->createSpec($viewer, ['title' => 'x', 'project_name' => 'y']));
    $env->specs->listSpecs($viewer, [], 10);
});

test('flipping visibility moves a spec in and out of other users listings', function (Env $env): void {
    $alice = $env->user();
    $bob = $env->user();
    $spec = $env->spec($alice, ['title' => 'Flip ' . $env->name('f')]);
    $titles = static fn () => array_column($env->specs->listSpecs($bob, [], 50)['items'], 'title');
    check(in_array($spec['title'], $titles(), true), 'visible while public');
    $updated = $env->specs->updateSpec($alice, $spec['id'], ['visibility' => 'private']);
    same(2, $updated['revision'], 'revision bumped');
    check(!in_array($spec['title'], $titles(), true), 'hidden once private');
    check(in_array($spec['title'], array_column($env->specs->listSpecs($alice, [], 50)['items'], 'title'), true), 'still in the authors listing');
    $env->specs->updateSpec($alice, $spec['id'], ['visibility' => 'public']);
    check(in_array($spec['title'], $titles(), true), 'visible again');
});

test('optimistic concurrency rejects a stale revision', function (Env $env): void {
    $owner = $env->user();
    $spec = $env->spec($owner);
    $env->specs->updateSpec($owner, $spec['id'], ['body' => 'v2'], 1);
    $exception = throws('CONFLICT', fn () => $env->specs->updateSpec($owner, $spec['id'], ['body' => 'v3'], 1));
    same(2, $exception->details()['current_revision'] ?? null, 'current revision reported');
    $env->specs->updateSpec($owner, $spec['id'], ['body' => 'v3'], 2);
    same('v3', $env->specs->getSpec($owner, $spec['id'])['body'], 'latest write wins');
});

test('delete is soft, bumps the revision and hides the spec everywhere', function (Env $env): void {
    $owner = $env->user();
    $other = $env->user();
    $spec = $env->spec($owner);
    $result = $env->specs->deleteSpec($owner, $spec['id'], 1);
    same(2, $result['revision'], 'revision bumped');
    throws('NOT_FOUND', fn () => $env->specs->getSpec($owner, $spec['id']));
    throws('NOT_FOUND', fn () => $env->specs->getSpec($other, $spec['id']));
    check(!in_array($spec['id'], array_column($env->specs->listSpecs($other, [], 50)['items'], 'id'), true), 'gone from listing');
    throws('NOT_FOUND', fn () => $env->specs->deleteSpec($owner, $spec['id']));
    $row = $env->store->getSpec($spec['id']);
    check($row !== null && $row['deleted_at'] !== null, 'row retained with deleted_at');
});

test('listing filters by project (case-insensitive), status and text', function (Env $env): void {
    $owner = $env->user();
    $project = 'Proj' . $env->name('p');
    $env->spec($owner, ['title' => 'Needle one', 'project_name' => $project, 'status' => 'active']);
    $env->spec($owner, ['title' => 'Other', 'project_name' => $project, 'description' => 'has a NEEDLE inside']);
    $env->spec($owner, ['title' => 'Elsewhere', 'project_name' => 'Different ' . $project]);

    same(2, count($env->specs->listSpecs($owner, ['project_name' => strtolower($project)], 50)['items']), 'project filter');
    same(1, count($env->specs->listSpecs($owner, ['project_name' => $project, 'status' => 'active'], 50)['items']), 'status filter');
    same(2, count($env->specs->listSpecs($owner, ['project_name' => $project, 'query' => 'needle'], 50)['items']), 'text filter');
});

test('pagination walks every visible spec once, newest first, across both streams', function (Env $env): void {
    $owner = $env->user();
    $project = 'Page' . $env->name('p');
    $expected = [];
    for ($i = 0; $i < 7; $i++) {
        $spec = $env->spec($owner, ['title' => 'P' . $i, 'project_name' => $project, 'visibility' => $i % 2 === 0 ? 'public' : 'private']);
        $expected[] = $spec['id'];
        usleep(2000);
    }
    $seen = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = $env->specs->listSpecs($owner, ['project_name' => $project], 3, $cursor);
        foreach ($page['items'] as $item) {
            $seen[] = $item['id'];
        }
        $cursor = $page['next_cursor'];
        $pages++;
    } while ($cursor !== null && $pages < 10);
    same(array_reverse($expected), $seen, 'every spec exactly once, newest first');
    same(3, $pages, 'three pages of 3/3/1');
    throws('INVALID_ARGUMENT', fn () => $env->specs->listSpecs($owner, ['status' => 'active'], 3, 'bogus'), 'tampered cursor');
});

test('projects list is the distinct set visible to the viewer', function (Env $env): void {
    $alice = $env->user();
    $bob = $env->user();
    $shared = 'Shared' . $env->name('x');
    $hidden = 'Hidden' . $env->name('x');
    $env->spec($alice, ['project_name' => $shared]);
    $env->spec($alice, ['project_name' => strtoupper($shared)]);
    $env->spec($alice, ['project_name' => $hidden, 'visibility' => 'private']);

    $index = static function (array $projects): array {
        $out = [];
        foreach ($projects as $project) {
            $out[strtolower($project['project_name'])] = $project['spec_count'];
        }
        return $out;
    };
    $forBob = $index($env->specs->listProjects($bob)['items']);
    same(2, $forBob[strtolower($shared)] ?? null, 'case variants merge');
    check(!isset($forBob[strtolower($hidden)]), 'private-only project invisible to others');
    check(isset($index($env->specs->listProjects($alice)['items'])[strtolower($hidden)]), 'author sees own private project');
});

test('signed share links bypass visibility but never outlive deletion', function (Env $env): void {
    $owner = $env->user();
    $spec = $env->spec($owner, ['visibility' => 'private', 'body' => '# secret']);
    $url = $env->specs->getShareUrl($owner, $spec['id']);
    $token = substr($url, strlen('https://special.example.test/share/'));
    same('# secret', $env->specs->getSpecByShareToken($token)['body'], 'link works without login');
    throws('NOT_FOUND', fn () => $env->specs->getSpecByShareToken(substr($token, 0, -1) . '0'), 'tampered token');
    throws('NOT_FOUND', fn () => $env->specs->getShareUrl($env->user(), $spec['id']), 'cannot mint links for strangers private specs');
    $env->specs->deleteSpec($owner, $spec['id']);
    throws('NOT_FOUND', fn () => $env->specs->getSpecByShareToken($token));
});

test('field validation is enforced before storage', function (Env $env): void {
    $owner = $env->user();
    throws('INVALID_ARGUMENT', fn () => $env->specs->createSpec($owner, ['title' => '', 'project_name' => 'p']));
    throws('INVALID_ARGUMENT', fn () => $env->specs->createSpec($owner, ['title' => 't', 'project_name' => 'p', 'visibility' => 'secret']));
    throws('INVALID_ARGUMENT', fn () => $env->specs->createSpec($owner, ['title' => 't', 'project_name' => 'p', 'status' => 'nope']));
    throws('INVALID_ARGUMENT', fn () => $env->specs->createSpec($owner, ['title' => 't', 'project_name' => 'p', 'unknown' => 1]));
    throws('INVALID_ARGUMENT', fn () => $env->specs->createSpec($owner, ['title' => 't', 'project_name' => 'p', 'body' => str_repeat('😀', 99_999)]), 'bytes over the DynamoDB-safe limit');
    $ok = $env->specs->createSpec($owner, ['title' => 't', 'project_name' => 'p', 'body' => str_repeat('a', 100_000)]);
    same(100_000, strlen((string) $env->specs->getSpec($owner, $ok['id'])['body']), 'large body round-trips');
});

test('mutations write audit records', function (Env $env): void {
    $owner = $env->user();
    $spec = $env->spec($owner);
    $env->specs->updateSpec($owner, $spec['id'], ['title' => 'renamed']);
    $actions = array_column($env->store->listAudit('spec', $spec['id']), 'action');
    same(['spec.updated', 'spec.created'], $actions, 'newest first');
    $entry = $env->store->listAudit('spec', $spec['id'])[0];
    same(['title'], $entry['metadata']['changed_fields'] ?? null, 'changed fields captured');
    same($owner->id, $entry['actor_user_id'], 'actor captured');
});

// ------------------------------------------------------------------ OAuth

/** @return array<string, mixed> */
function oauth_tokens(Env $env, Principal $user, string $clientId, string $verifier): array
{
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $request = $env->oauth->validateAuthorizationRequest([
        'client_id' => $clientId,
        'redirect_uri' => 'https://client.example.test/cb',
        'response_type' => 'code',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'resource' => 'https://special.example.test/mcp',
    ]);
    $code = $env->oauth->issueAuthorizationCode($user, $request, 'req-test');

    return $env->oauth->issueToken([
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'code' => $code,
        'redirect_uri' => 'https://client.example.test/cb',
        'code_verifier' => $verifier,
        'resource' => 'https://special.example.test/mcp',
    ], 'req-test') + ['_code' => $code];
}

function special_far(): string
{
    return Special\special_now(86400 * 30);
}

test('authorization code flow issues bearer tokens bound to the resource', function (Env $env): void {
    $user = $env->user();
    $client = $env->name('client');
    $env->oauth->registerClient($client, 'Test client', ['https://client.example.test/cb']);
    throws('CONFLICT', fn () => $env->oauth->registerClient($client, 'Again', ['https://client.example.test/cb']));
    $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $tokens = oauth_tokens($env, $user, $client, $verifier);
    same('Bearer', $tokens['token_type'], 'token type');
    $principal = $env->security->principalFromBearer($tokens['access_token']);
    check($principal !== null && $principal->id === $user->id, 'bearer resolves to the user');
    check($principal->scopes !== null && in_array('model.read', $principal->scopes, true), 'scopes carried');
    same(null, $env->security->principalFromBearer('not-a-token'), 'unknown token');
});

test('authorization codes are single use and PKCE-bound', function (Env $env): void {
    $user = $env->user();
    $client = $env->name('client');
    $env->oauth->registerClient($client, 'Test client', ['https://client.example.test/cb']);
    $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $tokens = oauth_tokens($env, $user, $client, $verifier);
    $replay = fn () => $env->oauth->issueToken([
        'grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $tokens['_code'],
        'redirect_uri' => 'https://client.example.test/cb', 'code_verifier' => $verifier,
        'resource' => 'https://special.example.test/mcp',
    ]);
    throws('INVALID_ARGUMENT', $replay, 'code replay');

    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $request = $env->oauth->validateAuthorizationRequest([
        'client_id' => $client, 'redirect_uri' => 'https://client.example.test/cb', 'response_type' => 'code',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => 'https://special.example.test/mcp',
    ]);
    $code = $env->oauth->issueAuthorizationCode($user, $request);
    throws('INVALID_ARGUMENT', fn () => $env->oauth->issueToken([
        'grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $code,
        'redirect_uri' => 'https://client.example.test/cb', 'code_verifier' => str_repeat('a', 50),
        'resource' => 'https://special.example.test/mcp',
    ]), 'wrong verifier');
    throws('INVALID_ARGUMENT', fn () => $env->oauth->issueToken([
        'grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $code,
        'redirect_uri' => 'https://client.example.test/cb', 'code_verifier' => $verifier,
        'resource' => 'https://elsewhere.example.test/mcp',
    ]), 'wrong resource');
});

test('refresh tokens rotate and a replayed token revokes the whole family', function (Env $env): void {
    $user = $env->user();
    $client = $env->name('client');
    $env->oauth->registerClient($client, 'Test client', ['https://client.example.test/cb']);
    $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $first = oauth_tokens($env, $user, $client, $verifier);
    $refresh = fn (string $token) => $env->oauth->issueToken([
        'grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => $token,
        'resource' => 'https://special.example.test/mcp',
    ], 'req-test');

    $second = $refresh($first['refresh_token']);
    check($second['access_token'] !== $first['access_token'], 'new access token');
    check($env->security->principalFromBearer($second['access_token']) !== null, 'rotated access token works');

    throws('INVALID_ARGUMENT', fn () => $refresh($first['refresh_token']), 'replayed refresh token');
    same(null, $env->security->principalFromBearer($second['access_token']), 'family revoked after replay');
    throws('INVALID_ARGUMENT', fn () => $refresh($second['refresh_token']), 'successor refresh token revoked too');
});

test('metadata-document and dynamic clients register as public clients', function (Env $env): void {
    $registered = $env->oauth->registerDynamicClient(['redirect_uris' => ['https://app.example.test/cb'], 'client_name' => 'Dyn']);
    same('public', $registered['client_type'], 'public client');
    $stored = $env->oauth->getClient($registered['client_id']);
    check($stored !== null && $stored['enabled'] === true, 'persisted');
    same(['https://app.example.test/cb'], json_decode($stored['redirect_uris'], true), 'redirect URIs persisted');
});

// ------------------------------------------------------------------ remember-me & throttling at the store boundary

test('remember tokens rotate atomically and only once', function (Env $env): void {
    $user = $env->user();
    $selector = bin2hex(random_bytes(16));
    $env->store->putRemember(['selector' => $selector, 'user_id' => $user->id, 'token_hash' => hash('sha256', 'secret'), 'expires_at' => special_far(), 'created_at' => Special\special_now(), 'last_used_at' => null]);
    $next = bin2hex(random_bytes(16));
    $row = ['selector' => $next, 'user_id' => $user->id, 'token_hash' => hash('sha256', 'secret2'), 'expires_at' => special_far(), 'created_at' => Special\special_now(), 'last_used_at' => Special\special_now()];
    check(!$env->store->rotateRemember($selector, hash('sha256', 'wrong'), $row), 'wrong hash refused');
    check($env->store->rotateRemember($selector, hash('sha256', 'secret'), $row), 'rotation succeeds');
    check(!$env->store->rotateRemember($selector, hash('sha256', 'secret'), $row), 'second use refused');
    same(null, $env->store->getRemember($selector), 'old selector gone');
    same($user->id, $env->store->getRemember($next)['user_id'], 'new selector stored');
});

test('login attempt rows use optimistic versioning', function (Env $env): void {
    $key = $env->name('attempt');
    $row = ['window_started_at' => Special\special_now(), 'attempt_count' => 1, 'blocked_until' => null];
    check($env->store->putLoginAttempt($key, $row, null, time() + 3600), 'create');
    check(!$env->store->putLoginAttempt($key, $row, null, time() + 3600), 'create twice refused');
    $stored = $env->store->getLoginAttempt($key);
    same(1, $stored['version'], 'initial version');
    check(!$env->store->putLoginAttempt($key, $row, 99, time() + 3600), 'stale version refused');
    check($env->store->putLoginAttempt($key, ['attempt_count' => 2] + $row, 1, time() + 3600), 'update with current version');
    same(2, $env->store->getLoginAttempt($key)['attempt_count'], 'updated count');
    $env->store->deleteLoginAttempt($key);
    same(null, $env->store->getLoginAttempt($key), 'deleted');
});

// ------------------------------------------------------------------ MCP surface

test('MCP exposes project listing and visibility-aware spec tools', function (Env $env): void {
    $alice = $env->user();
    $bob = $env->user();
    $project = 'Mcp' . $env->name('p');
    $env->spec($alice, ['project_name' => $project, 'title' => 'Visible']);
    $env->spec($alice, ['project_name' => $project, 'title' => 'Hidden', 'visibility' => 'private']);
    $adapter = new McpAdapter($env->specs, $env->security, $env->config);
    $call = static fn (Principal $who, string $tool, array $arguments) => $adapter->handle(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]],
        $who,
    );

    $projects = $call($bob, 'list_projects', []);
    check(in_array($project, array_column($projects['result']['structuredContent']['data']['items'], 'project_name'), true), 'project listed');
    $listed = $call($bob, 'list_specs', ['project_name' => $project]);
    same(['Visible'], array_column($listed['result']['structuredContent']['data']['items'], 'title'), 'bob sees only the public spec');
    $created = $call($bob, 'create_spec', ['title' => 'Via MCP', 'project_name' => $project, 'visibility' => 'private']);
    same('private', $created['result']['structuredContent']['data']['spec']['visibility'], 'visibility accepted over MCP');
    $denied = $call($bob, 'update_spec', ['id' => $env->specs->listSpecs($alice, ['query' => 'Visible'], 5)['items'][0]['id'], 'title' => 'nope']);
    same('FORBIDDEN', $denied['error']['data']['code'], 'non-author update refused over MCP');
});

// ------------------------------------------------------------------ first-run setup & password reset

test('setup token storage is create-once until it expires', function (Env $env): void {
    $store = new Special\Storage\MemoryStore();
    $now = Special\special_now();
    check($store->createSetupToken('h1', Special\special_now(60), $now), 'first create wins');
    check(!$store->createSetupToken('h2', Special\special_now(60), $now), 'second create refused while valid');
    same('h1', $store->getSetupToken()['token_hash'], 'first token kept');
    check($store->createSetupToken('h3', Special\special_now(120), Special\special_now(61)), 'expired token is replaced');
    $store->deleteSetupToken();
    same(null, $store->getSetupToken(), 'deleted');
    // The active store honours the same contract.
    $env->store->deleteSetupToken();
    check($env->store->createSetupToken('x1', Special\special_now(60), Special\special_now()), 'active store: create');
    check(!$env->store->createSetupToken('x2', Special\special_now(60), Special\special_now()), 'active store: create once');
    $env->store->deleteSetupToken();
});

test('web setup: generated token gates the first administrator, then the page is gone', function (Env $env): void {
    $log = tempnam(sys_get_temp_dir(), 'setup');
    $previous = ini_set('error_log', $log);
    try {
        $fresh = new Env(new Special\Storage\MemoryStore());
        check($fresh->security->needsSetup(), 'empty system needs setup');
        $fresh->security->ensureSetupToken();
        $fresh->security->ensureSetupToken();
        $logged = (string) file_get_contents($log);
        same(1, substr_count($logged, 'setup token'), 'token logged exactly once');
        preg_match('/: (\S+)\s*$/m', $logged, $matches);
        $token = $matches[1] ?? '';
        check(strlen($token) >= 20, 'token captured from the log');

        throws('UNAUTHENTICATED', fn () => $fresh->security->completeSetup('first@example.test', 'a long enough password', 'wrong-token', '203.0.113.7'), 'wrong token');
        throws('INVALID_ARGUMENT', fn () => $fresh->security->completeSetup('first@example.test', 'short', $token, '203.0.113.7'), 'right token, weak password');
        $admin = $fresh->security->completeSetup('first@example.test', 'a long enough password', $token, '203.0.113.7');
        same('admin', $admin->role, 'first user is an administrator');
        check(!$fresh->security->needsSetup(), 'setup is over');
        throws('NOT_FOUND', fn () => $fresh->security->completeSetup('second@example.test', 'a long enough password', $token, '203.0.113.7'), 'cannot run twice');
        same(null, $fresh->store->getSetupToken(), 'token consumed');
        $fresh->security->authenticate('first@example.test', 'a long enough password', '203.0.113.7');
        $created = array_values(array_filter($fresh->store->listAudit('user', 1), static fn (array $entry): bool => $entry['action'] === 'user.bootstrap_admin_created'));
        same('web-setup', $created[0]['metadata']['via'] ?? null, 'audit records the web path');
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
        @unlink($log);
    }
});

test('web setup: a configured token replaces generation and failures are throttled', function (Env $env): void {
    $store = new Special\Storage\MemoryStore();
    $config = $env->config;
    $config['setup_token'] = 'configured-setup-token-123456';
    $security = new Security($store, $config);
    $security->ensureSetupToken();
    same(null, $store->getSetupToken(), 'nothing generated when configured');
    for ($i = 0; $i < 3; $i++) {
        throws('UNAUTHENTICATED', fn () => $security->completeSetup('first@example.test', 'a long enough password', 'guess-' . $i, '198.51.100.20'));
    }
    throws('UNAUTHENTICATED', fn () => $security->completeSetup('first@example.test', 'a long enough password', 'configured-setup-token-123456', '198.51.100.20'), 'blocked even with the right token');
    $admin = $security->completeSetup('first@example.test', 'a long enough password', 'configured-setup-token-123456', '198.51.100.21');
    same('admin', $admin->role, 'other address succeeds');
});

test('admin password reset forces a change and cuts off old credentials', function (Env $env): void {
    $admin = $env->admin();
    $user = $env->user();
    $client = $env->name('c');
    $env->oauth->registerClient($client, 'Test', ['https://client.example.test/cb']);
    $verifier = static fn (): string => rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $tokens = oauth_tokens($env, $user, $client, $verifier());
    check($env->security->principalFromBearer($tokens['access_token']) !== null, 'token works before reset');

    throws('INVALID_ARGUMENT', fn () => $env->security->resetPassword($admin, $admin->id), 'self reset refused');
    throws('FORBIDDEN', fn () => $env->security->resetPassword($user, $admin->id), 'non-admin refused');
    $result = $env->security->resetPassword($admin, $user->id, 'req-test');
    $temporary = $result['temporary_password'];
    check(strlen($temporary) >= 12, 'temporary password is long enough');
    check($result['user']['must_change_password'] === true, 'flag set');
    same(null, $env->security->principalFromBearer($tokens['access_token']), 'old MCP token revoked');

    throws('UNAUTHENTICATED', fn () => $env->security->authenticate($user->username, 'a long enough password', '192.0.2.50'), 'old password dead');
    $signedIn = $env->security->authenticate($user->username, $temporary, '192.0.2.50');
    check($signedIn->mustChangePassword, 'principal carries the flag');

    $second = oauth_tokens($env, $user, $client, $verifier());
    same(null, $env->security->principalFromBearer($second['access_token']), 'MCP stays closed until the password is changed');

    $env->security->changePassword($signedIn, $user->id, 'my own new password');
    $after = $env->security->authenticate($user->username, 'my own new password', '192.0.2.50');
    check(!$after->mustChangePassword, 'flag cleared by the change');
    $third = oauth_tokens($env, $user, $client, $verifier());
    check($env->security->principalFromBearer($third['access_token']) !== null, 'MCP works again');
    check(in_array('user.password_reset', array_column($env->store->listAudit('user', $user->id), 'action'), true), 'reset is audited');
});

// ------------------------------------------------------------------ runner

$cleanup = static function (): void {
};
if ($mode === 'dynamodb') {
    putenv('SPECIAL_STORE=dynamodb');
    putenv('SPECIAL_DYNAMODB_TABLE=special-test-' . bin2hex(random_bytes(4)));
    if (!getenv('SPECIAL_DYNAMODB_ENDPOINT')) {
        fwrite(STDERR, "dynamodb mode needs SPECIAL_DYNAMODB_ENDPOINT (DynamoDB Local).\n");
        exit(2);
    }
    $config = special_config();
    $store = special_store($config);
    $client = $store->client();
    $table = (string) $config['dynamodb_table'];
    $client->createTable(DynamoSchema::createTableArguments($table));
    $client->waitUntil('TableExists', ['TableName' => $table]);
    $cleanup = static function () use ($client, $table): void {
        $client->deleteTable(['TableName' => $table]);
    };
} else {
    $store = new Special\Storage\MemoryStore();
}

$env = new Env($store);
foreach ($tests as $name => $body) {
    try {
        $body($env);
        $passed++;
        fwrite(STDOUT, "ok    {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDOUT, "FAIL  {$name}\n      " . get_class($exception) . ': ' . $exception->getMessage() . "\n      at " . basename($exception->getFile()) . ':' . $exception->getLine() . "\n");
    }
}
$cleanup();

fwrite(STDOUT, sprintf("\n%s store: %d passed, %d failed\n", $mode, $passed, $failures));
exit($failures === 0 ? 0 : 1);
