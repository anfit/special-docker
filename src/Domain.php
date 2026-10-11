<?php

declare(strict_types=1);

namespace Special;

use RuntimeException;

final class SpecialException extends RuntimeException
{
    /** @var array<string, mixed> */
    private array $details;

    public function __construct(
        private string $errorCode,
        string $message,
        private int $httpStatus = 400,
        array $details = [],
    ) {
        parent::__construct($message);
        $this->details = $details;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}

final class Principal
{
    /** @param list<string>|null $scopes */
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $role,
        public readonly ?array $scopes = null,
        public readonly string $source = 'web',
        public readonly ?string $clientId = null,
        public readonly bool $mustChangePassword = false,
    ) {
    }

    public function can(string $permission): bool
    {
        $rolePermissions = match ($this->role) {
            'viewer' => ['spec.read'],
            'editor' => ['spec.read', 'spec.write'],
            'admin' => ['spec.read', 'spec.write', 'users.manage'],
            default => [],
        };

        if (!in_array($permission, $rolePermissions, true)) {
            return false;
        }

        if ($this->scopes === null) {
            return true;
        }

        return in_array($permission, special_scope_permissions($this->scopes), true);
    }
}

/** @param list<string> $scopes @return list<string> */
function special_scope_permissions(array $scopes): array
{
    $permissions = [];
    foreach ($scopes as $scope) {
        if ($scope === 'mcp:tools' || $scope === 'model.read' || $scope === 'spec.read') {
            $permissions[] = 'spec.read';
        }
        if ($scope === 'mcp:tools' || $scope === 'model.write' || $scope === 'spec.write') {
            $permissions[] = 'spec.write';
        }
    }

    return array_values(array_unique($permissions));
}

function special_string_length(string $value): ?int
{
    if (preg_match('//u', $value) !== 1) {
        return null;
    }

    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    preg_match_all('/./us', $value, $matches);
    return count($matches[0]);
}

function special_b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function special_b64url_decode(string $value): ?string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return null;
    }

    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function special_normalize_username(string $username): string
{
    return strtolower(trim($username));
}

function special_validate_role(string $role): string
{
    if (!in_array($role, ['viewer', 'editor', 'admin'], true)) {
        throw new SpecialException('INVALID_ARGUMENT', 'Invalid user role.');
    }

    return $role;
}

function special_validate_status(string $status): string
{
    if (!in_array($status, ['draft', 'active', 'archived'], true)) {
        throw new SpecialException('INVALID_ARGUMENT', 'Invalid spec status.');
    }

    return $status;
}

function special_validate_visibility(string $visibility): string
{
    if (!in_array($visibility, ['public', 'private'], true)) {
        throw new SpecialException('INVALID_ARGUMENT', 'Invalid spec visibility.', 422);
    }

    return $visibility;
}

/** UTC timestamp in the fixed-width ISO-8601 form every store column uses; sorts lexicographically. */
function special_now(int $offsetSeconds = 0): string
{
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    if ($offsetSeconds !== 0) {
        $now = $now->modify(($offsetSeconds > 0 ? '+' : '') . $offsetSeconds . ' seconds');
    }

    return $now->format('Y-m-d\TH:i:s.u\Z');
}

function special_timestamp_is_future(?string $timestamp): bool
{
    if ($timestamp === null || $timestamp === '') {
        return false;
    }
    try {
        return new \DateTimeImmutable($timestamp) > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    } catch (\Exception) {
        return false;
    }
}

/** Sort key of a spec in the listing streams: newest first by (updated_at, id). */
function special_spec_key(string $updatedAt, int $id): string
{
    return $updatedAt . '#' . sprintf('%012d', $id);
}

/**
 * @param array<string, mixed> $metadata
 * @return array<string, mixed>
 */
function special_audit_entry(
    ?Principal $actor,
    string $action,
    string $entityType,
    ?int $entityId,
    array $metadata = [],
    ?string $requestId = null,
    ?string $clientId = null,
): array {
    return [
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'actor_user_id' => $actor?->id,
        'metadata' => $metadata,
        'request_id' => $requestId,
        'client_id' => $clientId ?? $actor?->clientId,
        'created_at' => special_now(),
    ];
}

