<?php

declare(strict_types=1);

namespace Special\Storage;

use Special\SpecialException;

/**
 * In-process reference implementation of Store.
 *
 * Used by the test-suite and for credential-free local experiments. It is
 * deliberately simple and single-process: when `$file` is given the state is
 * serialized to that file around every operation so a PHP development server
 * (one process per request) can share it. It is refused in production.
 */
final class MemoryStore implements Store
{
    /** @var array<string, mixed> */
    private array $state;

    public function __construct(private ?string $file = null)
    {
        $this->state = self::emptyState();
    }

    /** @return array<string, mixed> */
    private static function emptyState(): array
    {
        return [
            'counters' => ['spec' => 0, 'user' => 0, 'enabled_admins' => 0],
            'bootstrap_done' => false,
            'setup_token' => null,
            'users' => [],
            'usernames' => [],
            'specs' => [],
            'audit' => [],
            'login' => [],
            'remember' => [],
            'clients' => [],
            'codes' => [],
            'access' => [],
            'refresh' => [],
        ];
    }

    private function load(): void
    {
        if ($this->file === null || !is_file($this->file)) {
            return;
        }
        $raw = file_get_contents($this->file);
        $decoded = $raw === false || $raw === '' ? null : unserialize($raw, ['allowed_classes' => false]);
        $this->state = is_array($decoded) ? array_replace(self::emptyState(), $decoded) : self::emptyState();
    }

    private function save(): void
    {
        if ($this->file === null) {
            return;
        }
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($this->file, serialize($this->state), LOCK_EX);
    }

    public function ping(): void
    {
        $this->load();
    }

    // ---------------------------------------------------------------- users

    public function createUser(array $user, array $audit, bool $firstAdmin = false): array
    {
        $this->load();
        if ($firstAdmin && ($this->state['bootstrap_done'] || $this->state['counters']['enabled_admins'] > 0)) {
            throw new SpecialException('CONFLICT', 'An administrator already exists.', 409);
        }
        $lower = strtolower((string) $user['username']);
        if (isset($this->state['usernames'][$lower])) {
            throw new SpecialException('INVALID_ARGUMENT', 'That username is already in use.', 422);
        }
        $id = ++$this->state['counters']['user'];
        $now = (string) $user['created_at'];
        $row = [
            'id' => $id,
            'username' => (string) $user['username'],
            'password_hash' => (string) $user['password_hash'],
            'role' => (string) $user['role'],
            'enabled' => (bool) ($user['enabled'] ?? true),
            'must_change_password' => false,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => null,
        ];
        $this->state['users'][$id] = $row;
        $this->state['usernames'][$lower] = $id;
        if ($row['role'] === 'admin' && $row['enabled']) {
            $this->state['counters']['enabled_admins']++;
        }
        if ($firstAdmin) {
            $this->state['bootstrap_done'] = true;
        }
        $audit['entity_id'] = $id;
        $this->appendAudit($audit);
        $this->save();

        return $row;
    }

    public function getUser(int $id): ?array
    {
        $this->load();
        return $this->state['users'][$id] ?? null;
    }

    public function findUserByUsername(string $normalizedUsername): ?array
    {
        $this->load();
        $id = $this->state['usernames'][strtolower($normalizedUsername)] ?? null;
        return $id === null ? null : ($this->state['users'][$id] ?? null);
    }

    public function listUsers(): array
    {
        $this->load();
        $users = array_values($this->state['users']);
        usort($users, static fn (array $a, array $b): int => [strtolower($a['username']), $a['id']] <=> [strtolower($b['username']), $b['id']]);
        return $users;
    }

    public function updateUser(int $id, array $changes, int $expectedVersion, array $audit, int $enabledAdminDelta = 0): array
    {
        $this->load();
        $user = $this->state['users'][$id] ?? null;
        if ($user === null) {
            throw new SpecialException('NOT_FOUND', 'User not found.', 404);
        }
        if ((int) $user['version'] !== $expectedVersion) {
            throw new SpecialException('CONFLICT', 'The user was changed by another request.', 409, ['reason' => 'stale']);
        }
        if ($enabledAdminDelta < 0 && $this->state['counters']['enabled_admins'] + $enabledAdminDelta < 1) {
            throw new SpecialException('CONFLICT', 'The final enabled administrator cannot be disabled or demoted.', 409, ['reason' => 'last_admin']);
        }
        $this->state['counters']['enabled_admins'] += $enabledAdminDelta;
        $user = array_merge($user, $changes);
        $user['version'] = $expectedVersion + 1;
        $this->state['users'][$id] = $user;
        $this->appendAudit($audit);
        $this->save();

        return $user;
    }

    public function recordLogin(int $id, string $at, array $audit): void
    {
        $this->load();
        if (isset($this->state['users'][$id])) {
            $this->state['users'][$id]['last_login_at'] = $at;
        }
        $this->appendAudit($audit);
        $this->save();
    }

    public function needsSetup(): bool
    {
        $this->load();
        return !$this->state['bootstrap_done'] && $this->state['counters']['enabled_admins'] < 1;
    }

    public function getSetupToken(): ?array
    {
        $this->load();
        return $this->state['setup_token'] ?? null;
    }

    public function createSetupToken(string $tokenHash, string $expiresAt, string $now): bool
    {
        $this->load();
        $current = $this->state['setup_token'] ?? null;
        if ($current !== null && strcmp((string) $current['expires_at'], $now) > 0) {
            return false;
        }
        $this->state['setup_token'] = ['token_hash' => $tokenHash, 'expires_at' => $expiresAt];
        $this->save();

        return true;
    }

    public function deleteSetupToken(): void
    {
        $this->load();
        unset($this->state['setup_token']);
        $this->save();
    }

    // ---------------------------------------------------------------- specs

    public function createSpec(array $spec, array $audit): array
    {
        $this->load();
        $id = ++$this->state['counters']['spec'];
        $row = array_merge($spec, ['id' => $id, 'revision' => 1, 'deleted_at' => null]);
        $this->state['specs'][$id] = $row;
        $audit['entity_id'] = $id;
        $this->appendAudit($audit);
        $this->save();

        return $row;
    }

    public function getSpec(int $id): ?array
    {
        $this->load();
        return $this->state['specs'][$id] ?? null;
    }

    public function replaceSpec(array $spec, int $expectedRevision, array $audit): bool
    {
        $this->load();
        $current = $this->state['specs'][(int) $spec['id']] ?? null;
        if ($current === null || $current['deleted_at'] !== null || (int) $current['revision'] !== $expectedRevision) {
            return false;
        }
        $this->state['specs'][(int) $spec['id']] = $spec;
        $this->appendAudit($audit);
        $this->save();

        return true;
    }

    public function querySpecs(string $stream, ?string $before, int $limit): array
    {
        $this->load();
        $matches = [];
        foreach ($this->state['specs'] as $spec) {
            if ($spec['deleted_at'] !== null) {
                continue;
            }
            $inStream = $stream === 'public'
                ? $spec['visibility'] === 'public'
                : ($spec['visibility'] === 'private' && $stream === 'private:' . $spec['created_by']);
            if (!$inStream) {
                continue;
            }
            $key = \Special\special_spec_key((string) $spec['updated_at'], (int) $spec['id']);
            if ($before !== null && strcmp($key, $before) >= 0) {
                continue;
            }
            unset($spec['body']);
            $matches[$key] = $spec;
        }
        krsort($matches, SORT_STRING);

        return array_slice(array_values($matches), 0, $limit);
    }

    // ---------------------------------------------------------------- audit

    /** @param array<string, mixed> $audit */
    private function appendAudit(array $audit): void
    {
        $this->state['audit'][] = $audit;
    }

    public function writeAudit(array $audit): void
    {
        $this->load();
        $this->appendAudit($audit);
        $this->save();
    }

    public function listAudit(string $entityType, ?int $entityId, int $limit = 50): array
    {
        $this->load();
        $rows = array_values(array_filter(
            $this->state['audit'],
            static fn (array $entry): bool => $entry['entity_type'] === $entityType
                && ($entry['entity_id'] ?? null) === $entityId,
        ));

        return array_slice(array_reverse($rows), 0, $limit);
    }

    // ------------------------------------------------------ login throttling

    public function getLoginAttempt(string $key): ?array
    {
        $this->load();
        return $this->state['login'][$key] ?? null;
    }

    public function putLoginAttempt(string $key, array $row, ?int $expectedVersion, int $ttlAt): bool
    {
        $this->load();
        $current = $this->state['login'][$key] ?? null;
        if ($expectedVersion === null ? $current !== null : ($current === null || (int) $current['version'] !== $expectedVersion)) {
            return false;
        }
        $row['version'] = $expectedVersion === null ? 1 : $expectedVersion + 1;
        $this->state['login'][$key] = $row;
        $this->save();

        return true;
    }

    public function deleteLoginAttempt(string $key): void
    {
        $this->load();
        unset($this->state['login'][$key]);
        $this->save();
    }

    // ------------------------------------------------------ remember-me

    public function putRemember(array $row): void
    {
        $this->load();
        $this->state['remember'][(string) $row['selector']] = $row;
        $this->save();
    }

    public function getRemember(string $selector): ?array
    {
        $this->load();
        return $this->state['remember'][$selector] ?? null;
    }

    public function rotateRemember(string $oldSelector, string $expectedHash, array $newRow): bool
    {
        $this->load();
        $current = $this->state['remember'][$oldSelector] ?? null;
        if ($current === null || !hash_equals((string) $current['token_hash'], $expectedHash)) {
            return false;
        }
        unset($this->state['remember'][$oldSelector]);
        $this->state['remember'][(string) $newRow['selector']] = $newRow;
        $this->save();

        return true;
    }

    public function deleteRemember(string $selector): void
    {
        $this->load();
        unset($this->state['remember'][$selector]);
        $this->save();
    }

    // ---------------------------------------------------------------- OAuth

    public function getOAuthClient(string $clientId): ?array
    {
        $this->load();
        return $this->state['clients'][$clientId] ?? null;
    }

    public function putOAuthClient(array $client, bool $overwrite): bool
    {
        $this->load();
        $id = (string) $client['client_id'];
        if (isset($this->state['clients'][$id])) {
            if (!$overwrite) {
                return false;
            }
        }
        $this->state['clients'][$id] = $client;
        $this->save();

        return true;
    }

    public function putAuthCode(array $code, array $audit): void
    {
        $this->load();
        $this->state['codes'][(string) $code['code_hash']] = $code;
        $this->appendAudit($audit);
        $this->save();
    }

    public function getAuthCode(string $codeHash): ?array
    {
        $this->load();
        return $this->state['codes'][$codeHash] ?? null;
    }

    public function consumeAuthCode(string $codeHash, string $at): bool
    {
        $this->load();
        $code = $this->state['codes'][$codeHash] ?? null;
        if ($code === null || $code['consumed_at'] !== null) {
            return false;
        }
        $this->state['codes'][$codeHash]['consumed_at'] = $at;
        $this->save();

        return true;
    }

    public function issueTokens(array $access, array $refresh, array $audit): void
    {
        $this->load();
        $this->state['access'][(string) $access['token_hash']] = $access;
        $this->state['refresh'][(string) $refresh['token_hash']] = $refresh;
        $this->appendAudit($audit);
        $this->save();
    }

    public function getAccessToken(string $tokenHash): ?array
    {
        $this->load();
        return $this->state['access'][$tokenHash] ?? null;
    }

    public function getRefreshToken(string $tokenHash): ?array
    {
        $this->load();
        return $this->state['refresh'][$tokenHash] ?? null;
    }

    public function rotateRefresh(string $oldHash, string $at, array $access, array $refresh, array $audit): bool
    {
        $this->load();
        $old = $this->state['refresh'][$oldHash] ?? null;
        if ($old === null || $old['revoked_at'] !== null) {
            return false;
        }
        $this->state['refresh'][$oldHash]['revoked_at'] = $at;
        $this->state['refresh'][$oldHash]['last_used_at'] = $at;
        $this->state['refresh'][$oldHash]['replaced_by_hash'] = (string) $refresh['token_hash'];
        $this->state['access'][(string) $access['token_hash']] = $access;
        $this->state['refresh'][(string) $refresh['token_hash']] = $refresh;
        $this->appendAudit($audit);
        $this->save();

        return true;
    }

    public function revokeFamily(string $family, string $at): void
    {
        $this->load();
        foreach (['access', 'refresh'] as $kind) {
            foreach ($this->state[$kind] as $hash => $token) {
                if (($token['refresh_family'] ?? null) === $family && $token['revoked_at'] === null) {
                    $this->state[$kind][$hash]['revoked_at'] = $at;
                }
            }
        }
        $this->save();
    }

    public function revokeUserCredentials(int $userId, string $at): void
    {
        $this->load();
        foreach (['access', 'refresh'] as $kind) {
            foreach ($this->state[$kind] as $hash => $token) {
                if ((int) $token['user_id'] === $userId && $token['revoked_at'] === null) {
                    $this->state[$kind][$hash]['revoked_at'] = $at;
                }
            }
        }
        foreach ($this->state['codes'] as $hash => $code) {
            if ((int) $code['user_id'] === $userId && $code['consumed_at'] === null) {
                unset($this->state['codes'][$hash]);
            }
        }
        foreach ($this->state['remember'] as $selector => $row) {
            if ((int) $row['user_id'] === $userId) {
                unset($this->state['remember'][$selector]);
            }
        }
        $this->save();
    }
}
