<?php

declare(strict_types=1);

namespace Special;

use DateTimeImmutable;
use DateTimeZone;
use Special\Storage\Store;

final class Security
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'viewer' => ['spec.read'],
        'editor' => ['spec.read', 'spec.write'],
        'admin' => ['spec.read', 'spec.write', 'users.manage'],
    ];

    private const UPDATE_RETRIES = 4;
    private const SETUP_TOKEN_TTL_SECONDS = 86400;

    public function __construct(
        private Store $store,
        private array $config,
    ) {
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function authenticate(string $username, string $password, string $ipAddress, ?string $requestId = null): Principal
    {
        $normalizedUsername = special_normalize_username($username);
        $attemptKey = hash('sha256', $normalizedUsername . "\0" . trim($ipAddress));

        if ($this->isLoginBlocked($attemptKey)) {
            Metrics::inc('special_login_attempts_total', ['result' => 'blocked']);
            throw new SpecialException('UNAUTHENTICATED', 'Invalid username or password.', 401);
        }

        $user = $this->store->findUserByUsername($normalizedUsername);
        // Always burn a hash verification so unknown usernames cost the same as wrong passwords.
        $hash = $user === null ? password_hash('special-timing-equalizer', PASSWORD_DEFAULT) : (string) $user['password_hash'];
        $passwordMatches = password_verify($password, $hash);

        if ($user === null || !(bool) $user['enabled'] || !$passwordMatches) {
            Metrics::inc('special_login_attempts_total', ['result' => 'failure']);
            $this->recordLoginFailure($attemptKey);
            throw new SpecialException('UNAUTHENTICATED', 'Invalid username or password.', 401);
        }

        Metrics::inc('special_login_attempts_total', ['result' => 'success']);
        $this->store->deleteLoginAttempt($attemptKey);
        $principal = $this->principalFromRow($user, null, 'web');
        $this->store->recordLogin(
            (int) $user['id'],
            special_now(),
            special_audit_entry($principal, 'user.login', 'user', (int) $user['id'], [], $requestId),
        );

        return $principal;
    }

    /** @param array<string, mixed> $session */
    public function principalFromSession(array $session): ?Principal
    {
        $userId = $session['user_id'] ?? null;
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }

        return $this->loadPrincipal((int) $userId, null, 'web');
    }

    public function principalFromBearer(string $token): ?Principal
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 512) {
            return null;
        }

        $tokenHash = $this->tokenHash($token);
        $row = $this->store->getAccessToken($tokenHash);
        if ($row !== null
            && $row['revoked_at'] === null
            && special_timestamp_is_future((string) $row['expires_at'])
            && ($row['resource'] === null || (string) $row['resource'] === (string) $this->config['mcp_resource'])) {
            $user = $this->store->getUser((int) $row['user_id']);
            // A user who must choose a new password cannot use MCP until they have done so on the web.
            if ($user === null || !(bool) $user['enabled'] || (bool) ($user['must_change_password'] ?? false)) {
                return null;
            }

            $scopes = preg_split('/\s+/', trim((string) $row['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            return $this->principalFromRow($user, $scopes, 'mcp', (string) $row['client_id']);
        }

        $managed = $this->store->getApiToken($tokenHash);
        if ($managed === null || $managed['revoked_at'] !== null) {
            return null;
        }
        $user = $this->store->getUser((int) $managed['user_id']);
        // Admin-issued tokens follow the account's current role and remain subject to account lockout.
        if ($user === null || !(bool) $user['enabled'] || (bool) ($user['must_change_password'] ?? false)) {
            return null;
        }
        return $this->principalFromRow($user, null, 'mcp');
    }

    public function loadPrincipal(int $userId, ?array $scopes = null, string $source = 'web', ?string $clientId = null): ?Principal
    {
        $row = $this->store->getUser($userId);
        if ($row === null || !(bool) $row['enabled']) {
            return null;
        }

        return $this->principalFromRow($row, $scopes, $source, $clientId);
    }

    /** Username for display; unknown or removed users yield null. */
    public function usernameFor(int $userId): ?string
    {
        $row = $this->store->getUser($userId);
        return $row === null ? null : (string) $row['username'];
    }

    public function assertAuthenticated(?Principal $principal): Principal
    {
        if ($principal === null) {
            throw new SpecialException('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        return $principal;
    }

    public function assertPermission(?Principal $principal, string $permission): Principal
    {
        $principal = $this->assertAuthenticated($principal);
        if (!$principal->can($permission)) {
            $rolePermissions = self::ROLE_PERMISSIONS[$principal->role] ?? [];
            $requiredScope = match ($permission) {
                'spec.read' => OAuth::READ_SCOPE,
                'spec.write' => OAuth::WRITE_SCOPE,
                default => null,
            };
            if ($principal->scopes !== null
                && $requiredScope !== null
                && in_array($permission, $rolePermissions, true)
                && !in_array($permission, special_scope_permissions($principal->scopes), true)) {
                throw new SpecialException(
                    'FORBIDDEN',
                    'The MCP token does not grant the required scope.',
                    403,
                    ['oauth_error' => 'insufficient_scope', 'required_scope' => $requiredScope],
                );
            }
            throw new SpecialException('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }

        return $principal;
    }

    /** @return array<string, mixed> */
    public function createUser(Principal $actor, string $username, string $password, string $role, ?string $requestId = null): array
    {
        $this->assertPermission($actor, 'users.manage');
        $username = $this->validateUsername($username);
        $role = special_validate_role($role);
        $this->validatePassword($password);

        $user = $this->store->createUser(
            [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'enabled' => true,
                'created_at' => special_now(),
            ],
            special_audit_entry($actor, 'user.created', 'user', null, ['role' => $role], $requestId),
        );

        return $this->publicUser($user);
    }

    /** @return list<array<string, mixed>> */
    public function listUsers(Principal $actor): array
    {
        $this->assertPermission($actor, 'users.manage');
        return array_map(fn (array $row): array => $this->publicUser($row), $this->store->listUsers());
    }

    /** @return list<array<string, mixed>> */
    public function listApiTokens(Principal $actor, int $userId): array
    {
        $this->assertPermission($actor, 'users.manage');
        $userId = $this->validateUserId($userId);
        if ($this->store->getUser($userId) === null) {
            throw new SpecialException('NOT_FOUND', 'User not found.', 404);
        }
        return array_map(static fn (array $token): array => [
            'token_id' => (string) $token['token_id'],
            'name' => (string) $token['name'],
            'last_four' => (string) $token['last_four'],
            'created_at' => (string) $token['created_at'],
        ], $this->store->listApiTokens($userId));
    }

    /** @return array{user: array<string, mixed>, name: string, token: string} */
    public function issueApiToken(Principal $actor, int $userId, string $name, ?string $requestId = null): array
    {
        $this->assertPermission($actor, 'users.manage');
        $userId = $this->validateUserId($userId);
        $name = trim($name);
        $nameLength = special_string_length($name);
        if ($nameLength === null || $nameLength < 1 || $nameLength > 80) {
            throw new SpecialException('INVALID_ARGUMENT', 'Token name must be between 1 and 80 characters.', 422);
        }
        $user = $this->store->getUser($userId);
        if ($user === null) {
            throw new SpecialException('NOT_FOUND', 'User not found.', 404);
        }
        if (!(bool) $user['enabled'] || (bool) ($user['must_change_password'] ?? false)) {
            throw new SpecialException('CONFLICT', 'The user must be enabled and finish any required password change before receiving a bearer token.', 409);
        }

        $token = 'spc_' . special_b64url_encode(random_bytes(32));
        $this->store->issueApiToken(
            [
                'token_hash' => $this->tokenHash($token),
                'token_id' => bin2hex(random_bytes(16)),
                'user_id' => $userId,
                'name' => $name,
                'last_four' => substr($token, -4),
                'created_at' => special_now(),
                'created_by' => $actor->id,
            ],
            special_audit_entry($actor, 'user.api_token_issued', 'user', $userId, ['name' => $name], $requestId),
        );

        return ['user' => $this->publicUser($user), 'name' => $name, 'token' => $token];
    }

    public function revokeApiToken(Principal $actor, int $userId, string $tokenId, ?string $requestId = null): void
    {
        $this->assertPermission($actor, 'users.manage');
        $userId = $this->validateUserId($userId);
        if (preg_match('/^[a-f0-9]{32}$/', $tokenId) !== 1) {
            throw new SpecialException('NOT_FOUND', 'Bearer token not found.', 404);
        }
        if ($this->store->getUser($userId) === null) {
            throw new SpecialException('NOT_FOUND', 'User not found.', 404);
        }
        $this->store->revokeApiToken(
            $userId,
            $tokenId,
            special_now(),
            special_audit_entry($actor, 'user.api_token_revoked', 'user', $userId, ['token_id' => $tokenId], $requestId),
        );
    }

    /** @return array<string, mixed> */
    public function changeRole(Principal $actor, int $userId, string $role, ?string $requestId = null): array
    {
        $this->assertPermission($actor, 'users.manage');
        $userId = $this->validateUserId($userId);
        $role = special_validate_role($role);

        return $this->publicUser($this->updateWithRetry(
            $userId,
            static function (array $user) use ($role): array {
                $wasAdmin = $user['role'] === 'admin' && (bool) $user['enabled'];
                $willBeAdmin = $role === 'admin' && (bool) $user['enabled'];
                return [['role' => $role], $willBeAdmin <=> $wasAdmin];
            },
            static fn (array $user): array => special_audit_entry($actor, 'user.role_changed', 'user', $userId, ['from' => $user['role'], 'to' => $role], $requestId),
        ));
    }

    /** @return array<string, mixed> */
    public function setEnabled(Principal $actor, int $userId, bool $enabled, ?string $requestId = null): array
    {
        $this->assertPermission($actor, 'users.manage');
        $userId = $this->validateUserId($userId);

        $updated = $this->updateWithRetry(
            $userId,
            static function (array $user) use ($enabled): array {
                $wasAdmin = $user['role'] === 'admin' && (bool) $user['enabled'];
                $willBeAdmin = $user['role'] === 'admin' && $enabled;
                return [['enabled' => $enabled], $willBeAdmin <=> $wasAdmin];
            },
            static fn (array $user): array => special_audit_entry($actor, $enabled ? 'user.enabled' : 'user.disabled', 'user', $userId, [], $requestId),
        );
        if (!$enabled) {
            $this->revokeTokensForUser($userId);
        }

        return $this->publicUser($updated);
    }

    public function changePassword(Principal $actor, int $userId, string $password, ?string $requestId = null): void
    {
        $userId = $this->validateUserId($userId);
        if ($actor->id !== $userId) {
            throw new SpecialException('FORBIDDEN', 'You may only change your own password.', 403);
        }
        $this->validatePassword($password);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->updateWithRetry(
            $userId,
            static function (array $user) use ($hash): array {
                if (!(bool) $user['enabled']) {
                    throw new SpecialException('UNAUTHENTICATED', 'The user is not active.', 401);
                }
                return [['password_hash' => $hash, 'must_change_password' => false], 0];
            },
            static fn (array $user): array => special_audit_entry($actor, 'user.password_changed', 'user', $userId, [], $requestId),
        );
        $this->revokeTokensForUser($userId);
    }

    /**
     * Administrator-initiated reset: sets a random temporary password, forces a change at next login and
     * revokes the user's browser and MCP credentials. The temporary password is returned exactly once.
     *
     * @return array{user: array<string, mixed>, temporary_password: string}
     */
    public function resetPassword(Principal $actor, int $userId, ?string $requestId = null): array
    {
        $this->assertPermission($actor, 'users.manage');
        $userId = $this->validateUserId($userId);
        if ($actor->id === $userId) {
            throw new SpecialException('INVALID_ARGUMENT', 'Use the account page to change your own password.', 422);
        }

        $temporary = substr(special_b64url_encode(random_bytes(18)), 0, 20);
        $hash = password_hash($temporary, PASSWORD_DEFAULT);
        $updated = $this->updateWithRetry(
            $userId,
            static fn (array $user): array => [['password_hash' => $hash, 'must_change_password' => true], 0],
            static fn (array $user): array => special_audit_entry($actor, 'user.password_reset', 'user', $userId, [], $requestId),
        );
        $this->revokeTokensForUser($userId);

        return ['user' => $this->publicUser($updated), 'temporary_password' => $temporary];
    }

    public function needsSetup(): bool
    {
        return $this->store->needsSetup();
    }

    /**
     * Make sure a first-run setup token exists. Unless SPECIAL_SETUP_TOKEN supplies one, a random token is
     * generated, only its hash is stored, and the plaintext is written once to the error log (CloudWatch in AWS).
     */
    public function ensureSetupToken(): void
    {
        if (($this->config['setup_token'] ?? '') !== '' || !$this->store->needsSetup()) {
            return;
        }
        $token = special_b64url_encode(random_bytes(24));
        $expires = special_now(self::SETUP_TOKEN_TTL_SECONDS);
        if ($this->store->createSetupToken(hash('sha256', $token), $expires, special_now())) {
            error_log('Special first-run setup token (valid until ' . $expires . '): ' . $token);
        }
    }

    /** Create the first administrator from the web setup form. */
    public function completeSetup(string $username, string $password, string $token, string $ipAddress, ?string $requestId = null): Principal
    {
        if (!$this->store->needsSetup()) {
            throw new SpecialException('NOT_FOUND', 'Not found.', 404);
        }
        $attemptKey = hash('sha256', "setup\0" . trim($ipAddress));
        if ($this->isLoginBlocked($attemptKey) || !$this->setupTokenMatches($token)) {
            if (!$this->isLoginBlocked($attemptKey)) {
                $this->recordLoginFailure($attemptKey);
            }
            throw new SpecialException('UNAUTHENTICATED', 'The setup token is not valid.', 401);
        }

        $userId = $this->bootstrapFirstAdmin($username, $password, $requestId, 'web-setup');
        $this->store->deleteSetupToken();
        $this->store->deleteLoginAttempt($attemptKey);

        return new Principal($userId, special_normalize_username($username), 'admin');
    }

    private function setupTokenMatches(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 200) {
            return false;
        }
        $configured = (string) ($this->config['setup_token'] ?? '');
        if ($configured !== '') {
            return hash_equals(hash('sha256', $configured), hash('sha256', $token));
        }
        $row = $this->store->getSetupToken();

        return $row !== null
            && special_timestamp_is_future((string) $row['expires_at'])
            && hash_equals((string) $row['token_hash'], hash('sha256', $token));
    }

    public function bootstrapFirstAdmin(string $username, string $password, ?string $requestId = null, string $via = 'cli'): int
    {
        $username = $this->validateUsername($username);
        $this->validatePassword($password);

        return $this->insertFirstAdmin($username, password_hash($password, PASSWORD_DEFAULT), $requestId, $via);
    }

    public function bootstrapFirstAdminWithHash(string $username, string $passwordHash, ?string $requestId = null): int
    {
        $username = $this->validateUsername($username);
        $this->validatePasswordHash($passwordHash);

        return $this->insertFirstAdmin($username, $passwordHash, $requestId);
    }

    private function insertFirstAdmin(string $username, string $passwordHash, ?string $requestId, string $via = 'cli'): int
    {
        $user = $this->store->createUser(
            [
                'username' => $username,
                'password_hash' => $passwordHash,
                'role' => 'admin',
                'enabled' => true,
                'created_at' => special_now(),
            ],
            special_audit_entry(null, 'user.bootstrap_admin_created', 'user', null, ['via' => $via], $requestId),
            true,
        );

        return (int) $user['id'];
    }

    public function revokeTokensForUser(int $userId): void
    {
        $this->store->revokeUserCredentials($userId, special_now());
    }

    /** @param array<string, mixed> $metadata */
    public function audit(
        ?Principal $actor,
        string $action,
        string $entityType,
        ?int $entityId,
        array $metadata = [],
        ?string $requestId = null,
        ?string $clientId = null,
    ): void {
        $this->store->writeAudit(special_audit_entry($actor, $action, $entityType, $entityId, $metadata, $requestId, $clientId));
    }

    public function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) $this->config['app_key']);
    }

    /** @return list<string> */
    public function rolePermissions(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    /**
     * Read-modify-write on a user with optimistic versioning.
     *
     * @param callable(array<string, mixed>): array{0: array<string, mixed>, 1: int} $plan returns [changes, enabledAdminDelta]
     * @param callable(array<string, mixed>): array<string, mixed> $audit builds the audit entry for the current row
     * @return array<string, mixed>
     */
    private function updateWithRetry(int $userId, callable $plan, callable $audit): array
    {
        for ($attempt = 1; ; $attempt++) {
            $user = $this->store->getUser($userId);
            if ($user === null) {
                throw new SpecialException('NOT_FOUND', 'User not found.', 404);
            }
            [$changes, $delta] = $plan($user);
            $changes['updated_at'] = special_now();
            try {
                return $this->store->updateUser($userId, $changes, (int) $user['version'], $audit($user), $delta);
            } catch (SpecialException $exception) {
                $stale = $exception->errorCode() === 'CONFLICT' && ($exception->details()['reason'] ?? null) === 'stale';
                if (!$stale || $attempt >= self::UPDATE_RETRIES) {
                    throw $exception;
                }
            }
        }
    }

    private function isLoginBlocked(string $attemptKey): bool
    {
        $row = $this->store->getLoginAttempt($attemptKey);
        return $row !== null && special_timestamp_is_future($row['blocked_until'] ?? null);
    }

    private function recordLoginFailure(string $attemptKey): void
    {
        $windowSeconds = (int) $this->config['login_window_seconds'];
        $maxAttempts = (int) $this->config['login_max_attempts'];
        $blockSeconds = (int) $this->config['login_block_seconds'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = $this->store->getLoginAttempt($attemptKey);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $withinWindow = $row !== null
                && ($now->getTimestamp() - (new DateTimeImmutable((string) $row['window_started_at']))->getTimestamp()) < $windowSeconds;
            $attemptCount = $withinWindow ? (int) $row['attempt_count'] + 1 : 1;
            $blockedUntil = $attemptCount >= $maxAttempts ? special_now($blockSeconds) : null;

            $written = $this->store->putLoginAttempt(
                $attemptKey,
                [
                    'window_started_at' => $withinWindow ? (string) $row['window_started_at'] : special_now(),
                    'attempt_count' => $attemptCount,
                    'blocked_until' => $blockedUntil,
                ],
                $row === null ? null : (int) $row['version'],
                time() + max($windowSeconds, $blockSeconds) + 3600,
            );
            if ($written) {
                return;
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function principalFromRow(array $row, ?array $scopes, string $source, ?string $clientId = null): Principal
    {
        return new Principal(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['role'],
            $scopes,
            $source,
            $clientId,
            (bool) ($row['must_change_password'] ?? false),
        );
    }

    /** @param array<string, mixed> $row */
    private function publicUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
            'enabled' => (bool) $row['enabled'],
            'must_change_password' => (bool) ($row['must_change_password'] ?? false),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'last_login_at' => ($row['last_login_at'] ?? null) === null ? null : (string) $row['last_login_at'],
        ];
    }

    private function validateUserId(int $userId): int
    {
        if ($userId < 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'User ID must be a positive integer.', 422);
        }

        return $userId;
    }

    private function validateUsername(string $username): string
    {
        $username = special_normalize_username($username);
        $length = special_string_length($username);
        $validEmail = str_contains($username, '@')
            && filter_var($username, FILTER_VALIDATE_EMAIL) === $username;
        $validHandle = preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._-]{2,99}$/u', $username) === 1;
        if ($length === null || $length < 3 || $length > 100 || (!$validEmail && !$validHandle)) {
            throw new SpecialException('INVALID_ARGUMENT', 'Username must be a 3–100 character login name or email address.', 422);
        }

        return $username;
    }

    private function validatePasswordHash(string $passwordHash): void
    {
        $passwordInfo = password_get_info($passwordHash);
        if (($passwordInfo['algo'] ?? 0) === 0) {
            throw new SpecialException('INVALID_ARGUMENT', 'Password hash is not supported.', 422);
        }
    }

    private function validatePassword(string $password): void
    {
        $length = special_string_length($password);
        if ($length === null || $length < 12 || $length > 1024) {
            throw new SpecialException('INVALID_ARGUMENT', 'Password must be between 12 and 1024 characters.', 422);
        }
    }
}
