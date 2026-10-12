<?php

declare(strict_types=1);

namespace Special\Storage;

/**
 * Persistence boundary for Special.
 *
 * Every method is a domain-level operation. Implementations are responsible
 * for atomicity: whenever a method accepts an `$audit` entry, the entry must be
 * committed together with the mutation (or not at all). Rows returned to
 * callers are plain associative arrays using the domain field names below;
 * storage keys and indexes never leak out.
 *
 * Timestamps are ISO-8601 UTC strings with microseconds
 * (`2026-01-02T03:04:05.123456Z`) produced by `special_now()`. They sort
 * lexicographically.
 *
 * Row shapes
 * ----------
 * user:     id, username, password_hash, role, enabled, must_change_password, version, created_at, updated_at, last_login_at
 * spec:     id, title, description, body, project_name, status, visibility, revision,
 *           created_by, updated_by, created_at, updated_at, deleted_at
 * audit:    action, entity_type, entity_id, actor_user_id, metadata (array), request_id, client_id, created_at
 * remember: selector, user_id, token_hash, expires_at, created_at, last_used_at
 * client:   client_id, client_name, client_type, client_secret_hash, redirect_uris (JSON string), enabled, created_at
 * code:     code_hash, client_id, user_id, redirect_uri, resource, scope, code_challenge,
 *           code_challenge_method, expires_at, consumed_at, created_at
 * access:   token_hash, client_id, user_id, resource, refresh_family, scope, expires_at, revoked_at, created_at
 * refresh:  token_hash, client_id, user_id, resource, refresh_family, scope, expires_at, revoked_at,
 *           replaced_by_hash, last_used_at, created_at
 * api token: token_hash, token_id, user_id, name, last_four, created_at, created_by, revoked_at
 */
interface Store
{
    /** Cheap reachability check used by /health. Throws on failure. */
    public function ping(): void;

    // ---------------------------------------------------------------- users

    /**
     * Insert a user. `$user` carries username (already normalized), password_hash, role, enabled, created_at.
     * Throws SpecialException(INVALID_ARGUMENT) when the username is taken.
     * With `$firstAdmin` the insert only succeeds once for the lifetime of the store
     * (SpecialException CONFLICT afterwards).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $audit entry without entity_id; the new user id is filled in
     * @return array<string, mixed>
     */
    public function createUser(array $user, array $audit, bool $firstAdmin = false): array;

    /** @return array<string, mixed>|null */
    public function getUser(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function findUserByUsername(string $normalizedUsername): ?array;

    /** @return list<array<string, mixed>> ordered by username, id; password_hash is not guaranteed */
    public function listUsers(): array;

    /**
     * Apply `$changes` (role, enabled, password_hash, updated_at) if the user's version still equals
     * `$expectedVersion`, bumping the version. `$enabledAdminDelta` (-1, 0, +1) adjusts the enabled
     * administrator count atomically and fails with CONFLICT rather than dropping it below one.
     * Throws CONFLICT (details.reason = stale|last_admin) and NOT_FOUND.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $audit
     * @return array<string, mixed> the updated user
     */
    public function updateUser(int $id, array $changes, int $expectedVersion, array $audit, int $enabledAdminDelta = 0): array;

    /** @param array<string, mixed> $audit */
    public function recordLogin(int $id, string $at, array $audit): void;

    /** True while the system has no administrator and the one-shot bootstrap has not run. */
    public function needsSetup(): bool;

    /** @return array{token_hash: string, expires_at: string}|null */
    public function getSetupToken(): ?array;

    /**
     * Store the hash of a freshly generated setup token unless a still-valid one exists.
     * Returns true only for the caller that created it (that caller reports the plaintext).
     */
    public function createSetupToken(string $tokenHash, string $expiresAt, string $now): bool;

    public function deleteSetupToken(): void;

    // ---------------------------------------------------------------- specs

    /**
     * Allocate an id and insert the spec.
     *
     * @param array<string, mixed> $spec all fields except id and revision (revision starts at 1)
     * @param array<string, mixed> $audit entry without entity_id
     * @return array<string, mixed>
     */
    public function createSpec(array $spec, array $audit): array;

    /** @return array<string, mixed>|null includes soft-deleted rows */
    public function getSpec(int $id): ?array;

    /**
     * Replace a spec row if its stored revision equals `$expectedRevision` and it is not deleted.
     * `$spec` is the complete new row (revision = expectedRevision + 1).
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $audit
     * @return bool false when the revision moved or the row was deleted meanwhile
     */
    public function replaceSpec(array $spec, int $expectedRevision, array $audit): bool;

    /**
     * Page through live specs newest-first.
     *
     * `$stream` is `public` (every public spec) or `private:<userId>` (that user's private specs).
     * `$before` is an exclusive upper bound produced by `special_spec_key()`; null starts at the newest.
     * Rows omit `body`.
     *
     * @return list<array<string, mixed>>
     */
    public function querySpecs(string $stream, ?string $before, int $limit): array;

    // ---------------------------------------------------------------- audit

    /** @param array<string, mixed> $audit */
    public function writeAudit(array $audit): void;

    /** @return list<array<string, mixed>> newest first */
    public function listAudit(string $entityType, ?int $entityId, int $limit = 50): array;

    // ------------------------------------------------------ login throttling

    /** @return array<string, mixed>|null window_started_at, attempt_count, blocked_until, version */
    public function getLoginAttempt(string $key): ?array;

    /**
     * Write the attempt row. `$expectedVersion` null means "must not exist yet".
     * Returns false when another request wrote first. `$ttlAt` is the epoch after which the row is garbage.
     *
     * @param array<string, mixed> $row
     */
    public function putLoginAttempt(string $key, array $row, ?int $expectedVersion, int $ttlAt): bool;

    public function deleteLoginAttempt(string $key): void;

    // ------------------------------------------------------ remember-me

    /** @param array<string, mixed> $row */
    public function putRemember(array $row): void;

    /** @return array<string, mixed>|null */
    public function getRemember(string $selector): ?array;

    /**
     * Atomically replace a remember token: succeeds only while the old selector still holds `$expectedHash`.
     *
     * @param array<string, mixed> $newRow
     */
    public function rotateRemember(string $oldSelector, string $expectedHash, array $newRow): bool;

    public function deleteRemember(string $selector): void;

    // ---------------------------------------------------------------- OAuth

    /** @return array<string, mixed>|null */
    public function getOAuthClient(string $clientId): ?array;

    /**
     * @param array<string, mixed> $client
     * @return bool false when the client exists and `$overwrite` is false
     */
    public function putOAuthClient(array $client, bool $overwrite): bool;

    /**
     * @param array<string, mixed> $code
     * @param array<string, mixed> $audit
     */
    public function putAuthCode(array $code, array $audit): void;

    /** @return array<string, mixed>|null */
    public function getAuthCode(string $codeHash): ?array;

    /** Mark the code consumed; false if it was already consumed (single use). */
    public function consumeAuthCode(string $codeHash, string $at): bool;

    /**
     * @param array<string, mixed> $access
     * @param array<string, mixed> $refresh
     * @param array<string, mixed> $audit
     */
    public function issueTokens(array $access, array $refresh, array $audit): void;

    /** @return array<string, mixed>|null */
    public function getAccessToken(string $tokenHash): ?array;

    /** @return array<string, mixed>|null */
    public function getRefreshToken(string $tokenHash): ?array;

    /** @param array<string, mixed> $token @param array<string, mixed> $audit */
    public function issueApiToken(array $token, array $audit): void;

    /** @return array<string, mixed>|null */
    public function getApiToken(string $tokenHash): ?array;

    /** @return list<array<string, mixed>> active tokens for one user, newest first */
    public function listApiTokens(int $userId): array;

    /** Revoke one active token and append its audit entry atomically. */
    public function revokeApiToken(int $userId, string $tokenId, string $at, array $audit): void;

    /**
     * Revoke the presented refresh token and issue its replacement pair in one step.
     * Returns false when the presented token was already revoked (replay).
     *
     * @param array<string, mixed> $access
     * @param array<string, mixed> $refresh
     * @param array<string, mixed> $audit
     */
    public function rotateRefresh(string $oldHash, string $at, array $access, array $refresh, array $audit): bool;

    public function revokeFamily(string $family, string $at): void;

    /** Revoke MCP tokens, delete unconsumed codes and remember tokens for a user. */
    public function revokeUserCredentials(int $userId, string $at): void;
}
