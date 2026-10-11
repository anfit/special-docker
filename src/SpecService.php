<?php

declare(strict_types=1);

namespace Special;

use Special\Storage\Store;

final class SpecService
{
    /** @var list<string> */
    private const PRODUCT_FIELDS = ['title', 'description', 'body', 'project_name', 'status', 'visibility'];
    private const SHARE_TOKEN_VERSION = 1;
    private const SHARE_TOKEN_MAX_LENGTH = 512;
    /** DynamoDB items are capped at 400 KB; the body gets most of that, the rest is metadata. */
    private const BODY_MAX_BYTES = 350_000;
    private const STREAM_PAGE = 50;
    private const LIST_SCAN_CAP = 1000;
    private const PROJECT_SCAN_CAP = 20_000;
    private const WRITE_RETRIES = 4;

    /** @var array<int, ?string> */
    private array $authors = [];

    public function __construct(
        private Store $store,
        private Security $security,
        private array $config,
        private ?string $requestId = null,
    ) {
    }

    /**
     * Specs visible to the principal: every public spec plus the principal's own private specs.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function listSpecs(Principal $principal, array $filters = [], int $limit = 20, ?string $cursor = null): array
    {
        $this->security->assertPermission($principal, 'spec.read');
        $limit = $this->validateLimit($limit);
        $normalizedFilters = $this->normalizeFilters($filters);
        $cursorData = $this->decodeCursor($cursor, $normalizedFilters);
        $before = $cursorData === null ? null : special_spec_key($cursorData['updated_at'], $cursorData['id']);

        $items = [];
        $scanned = 0;
        $lastScanned = null;
        $anchor = null;
        foreach ($this->scan($principal, $before) as $row) {
            if (++$scanned > self::LIST_SCAN_CAP) {
                // Sparse filter: hand back what we have and let the client continue from the scan position.
                $anchor = $lastScanned;
                break;
            }
            $lastScanned = $row;
            if (!$this->matches($row, $normalizedFilters)) {
                continue;
            }
            if (count($items) === $limit) {
                $anchor = $items[count($items) - 1];
                break;
            }
            $items[] = $this->summary($row, $principal);
        }

        $nextCursor = $anchor === null
            ? null
            : $this->encodeCursor((string) $anchor['updated_at'], (int) $anchor['id'], $normalizedFilters);

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    /**
     * Distinct project names across the specs visible to the principal.
     *
     * @return array{items: list<array{project_name: string, spec_count: int}>}
     */
    public function listProjects(Principal $principal): array
    {
        $this->security->assertPermission($principal, 'spec.read');

        $projects = [];
        $scanned = 0;
        foreach ($this->scan($principal, null) as $row) {
            if (++$scanned > self::PROJECT_SCAN_CAP) {
                break;
            }
            $name = (string) $row['project_name'];
            $key = $this->fold($name);
            if (!isset($projects[$key])) {
                $projects[$key] = ['project_name' => $name, 'spec_count' => 0];
            }
            $projects[$key]['spec_count']++;
        }
        ksort($projects, SORT_STRING);

        return ['items' => array_values($projects)];
    }

    /** @return array<string, mixed> */
    public function getSpec(Principal $principal, int $specId): array
    {
        $this->security->assertPermission($principal, 'spec.read');
        return $this->formatSpec($this->loadVisible($principal, $this->validateId($specId)));
    }

    public function getShareUrl(Principal $principal, int $specId): string
    {
        $this->security->assertPermission($principal, 'spec.read');
        $specId = $this->validateId($specId);
        $this->loadVisible($principal, $specId);

        return $this->shareUrlFor($specId);
    }

    /** Permalink for a spec the caller has already been shown; performs no access check of its own. */
    public function shareUrlFor(int $specId): string
    {
        $payload = json_encode([
            'v' => self::SHARE_TOKEN_VERSION,
            'id' => $specId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encodedPayload = special_b64url_encode($payload);
        $signature = hash_hmac('sha256', $payload, $this->shareSigningKey());

        return rtrim((string) $this->config['app_url'], '/')
            . '/share/' . $encodedPayload . '.' . $signature;
    }

    /** @return array<string, mixed> */
    public function getSpecByShareToken(string $token): array
    {
        $row = $this->store->getSpec($this->decodeShareToken($token));
        if ($row === null || $row['deleted_at'] !== null) {
            throw new SpecialException('NOT_FOUND', 'Shared spec not found.', 404);
        }

        return $this->formatSpec($row);
    }

    /** @param array<string, mixed> $fields */
    public function createSpec(Principal $principal, array $fields): array
    {
        $this->security->assertPermission($principal, 'spec.write');
        $values = $this->validateCreateFields($fields);
        $now = special_now();

        $row = $this->store->createSpec(
            $values + [
                'created_by' => $principal->id,
                'updated_by' => $principal->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            special_audit_entry(
                $principal,
                'spec.created',
                'spec',
                null,
                ['changed_fields' => self::PRODUCT_FIELDS, 'to_revision' => 1, 'visibility' => $values['visibility']],
                $this->requestId,
            ),
        );

        return $this->formatSpec($row);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function updateSpec(Principal $principal, int $specId, array $patch, ?int $expectedRevision = null): array
    {
        $this->security->assertPermission($principal, 'spec.write');
        $specId = $this->validateId($specId);
        if ($expectedRevision !== null && $expectedRevision < 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'expected_revision must be positive.', 422);
        }

        $values = $this->validatePatch($patch);
        if ($values === []) {
            throw new SpecialException('INVALID_ARGUMENT', 'At least one spec field must be supplied.', 422);
        }

        for ($attempt = 1; $attempt <= self::WRITE_RETRIES; $attempt++) {
            $row = $this->loadOwned($principal, $specId, $expectedRevision);
            $updated = array_merge($row, $values, [
                'revision' => (int) $row['revision'] + 1,
                'updated_by' => $principal->id,
                'updated_at' => special_now(),
            ]);
            $audit = special_audit_entry(
                $principal,
                'spec.updated',
                'spec',
                $specId,
                [
                    'changed_fields' => array_keys($values),
                    'from_revision' => (int) $row['revision'],
                    'to_revision' => (int) $updated['revision'],
                ],
                $this->requestId,
            );
            if ($this->store->replaceSpec($updated, (int) $row['revision'], $audit)) {
                return $this->formatSpec($updated);
            }
        }

        throw new SpecialException('CONFLICT', 'The spec could not be updated.', 409);
    }

    public function deleteSpec(Principal $principal, int $specId, ?int $expectedRevision = null): array
    {
        $this->security->assertPermission($principal, 'spec.write');
        $specId = $this->validateId($specId);
        if ($expectedRevision !== null && $expectedRevision < 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'expected_revision must be positive.', 422);
        }

        for ($attempt = 1; $attempt <= self::WRITE_RETRIES; $attempt++) {
            $row = $this->loadOwned($principal, $specId, $expectedRevision);
            $now = special_now();
            $deleted = array_merge($row, [
                'deleted_at' => $now,
                'revision' => (int) $row['revision'] + 1,
                'updated_by' => $principal->id,
                'updated_at' => $now,
            ]);
            $audit = special_audit_entry(
                $principal,
                'spec.deleted',
                'spec',
                $specId,
                ['from_revision' => (int) $row['revision'], 'to_revision' => (int) $deleted['revision']],
                $this->requestId,
            );
            if ($this->store->replaceSpec($deleted, (int) $row['revision'], $audit)) {
                return [
                    'id' => $specId,
                    'revision' => (int) $deleted['revision'],
                    'deleted_at' => $now,
                ];
            }
        }

        throw new SpecialException('CONFLICT', 'The spec could not be deleted.', 409);
    }

    /**
     * Newest-first merge of the public stream and the principal's private stream.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function scan(Principal $principal, ?string $before): \Generator
    {
        $streams = ['public', 'private:' . $principal->id];
        $buffers = [[], []];
        $cursors = [$before, $before];
        $done = [false, false];

        while (true) {
            foreach ([0, 1] as $i) {
                if ($buffers[$i] !== [] || $done[$i]) {
                    continue;
                }
                $rows = $this->store->querySpecs($streams[$i], $cursors[$i], self::STREAM_PAGE);
                if (count($rows) < self::STREAM_PAGE) {
                    $done[$i] = true;
                }
                if ($rows !== []) {
                    $buffers[$i] = $rows;
                    $last = $rows[count($rows) - 1];
                    $cursors[$i] = special_spec_key((string) $last['updated_at'], (int) $last['id']);
                }
            }

            $pick = null;
            $pickKey = null;
            foreach ([0, 1] as $i) {
                if ($buffers[$i] === []) {
                    continue;
                }
                $key = special_spec_key((string) $buffers[$i][0]['updated_at'], (int) $buffers[$i][0]['id']);
                if ($pickKey === null || strcmp($key, $pickKey) > 0) {
                    $pick = $i;
                    $pickKey = $key;
                }
            }
            if ($pick === null) {
                return;
            }

            yield array_shift($buffers[$pick]);
        }
    }

    /** @return array<string, mixed> */
    private function loadVisible(Principal $principal, int $specId): array
    {
        $row = $this->store->getSpec($specId);
        if ($row === null || $row['deleted_at'] !== null || !$this->canView($principal, $row)) {
            throw new SpecialException('NOT_FOUND', 'Spec not found.', 404);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function loadOwned(Principal $principal, int $specId, ?int $expectedRevision): array
    {
        $row = $this->loadVisible($principal, $specId);
        if ((int) $row['created_by'] !== $principal->id) {
            throw new SpecialException('FORBIDDEN', 'Only the author can change this spec.', 403);
        }
        if ($expectedRevision !== null && (int) $row['revision'] !== $expectedRevision) {
            throw new SpecialException(
                'CONFLICT',
                'The spec was changed by another edit.',
                409,
                ['current_revision' => (int) $row['revision']],
            );
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function canView(Principal $principal, array $row): bool
    {
        return $row['visibility'] === 'public' || (int) $row['created_by'] === $principal->id;
    }

    /** @param array<string, mixed> $fields */
    private function validateCreateFields(array $fields): array
    {
        $this->rejectUnknownFields($fields, self::PRODUCT_FIELDS);
        if (!array_key_exists('title', $fields) || !array_key_exists('project_name', $fields)) {
            throw new SpecialException('INVALID_ARGUMENT', 'title and project_name are required.', 422);
        }

        // Absent and null both mean "use the default" on create, so form posts with blank selects work.
        $fields = array_filter(
            $fields,
            static fn (mixed $value, string $field): bool => $value !== null || in_array($field, ['description', 'body'], true),
            ARRAY_FILTER_USE_BOTH,
        );
        $values = $this->validatePatch($fields);
        if (!isset($values['title'], $values['project_name'])) {
            throw new SpecialException('INVALID_ARGUMENT', 'title and project_name are required.', 422);
        }

        $values['description'] = $values['description'] ?? null;
        $values['body'] = $values['body'] ?? null;
        $values['status'] = $values['status'] ?? 'draft';
        $values['visibility'] = $values['visibility'] ?? 'public';
        return $values;
    }

    /** @param array<string, mixed> $patch */
    private function validatePatch(array $patch): array
    {
        $this->rejectUnknownFields($patch, self::PRODUCT_FIELDS);
        $values = [];
        foreach ($patch as $field => $value) {
            if ($field === 'title' || $field === 'project_name') {
                if (!is_string($value)) {
                    throw new SpecialException('INVALID_ARGUMENT', $field . ' must be a string.', 422);
                }
                $value = trim($value);
                $length = special_string_length($value);
                if ($length === null || $length < 1 || $length > 200) {
                    throw new SpecialException('INVALID_ARGUMENT', $field . ' must be between 1 and 200 characters.', 422);
                }
            } elseif ($field === 'description') {
                if ($value !== null && !is_string($value)) {
                    throw new SpecialException('INVALID_ARGUMENT', 'description must be a string or null.', 422);
                }
                if (is_string($value) && (($length = special_string_length($value)) === null || $length > 2000)) {
                    throw new SpecialException('INVALID_ARGUMENT', 'description must be at most 2,000 characters.', 422);
                }
                // An empty description means "none"; DynamoDB attributes are omitted rather than stored empty.
                $value = $value === '' ? null : $value;
            } elseif ($field === 'body') {
                if ($value !== null && !is_string($value)) {
                    throw new SpecialException('INVALID_ARGUMENT', 'body must be a string or null.', 422);
                }
                if (is_string($value) && (($length = special_string_length($value)) === null || $length > 100000)) {
                    throw new SpecialException('INVALID_ARGUMENT', 'body must be at most 100,000 characters.', 422);
                }
                if (is_string($value) && strlen($value) > self::BODY_MAX_BYTES) {
                    throw new SpecialException('INVALID_ARGUMENT', 'body must be at most 350,000 bytes.', 422);
                }
                $value = $value === '' ? null : $value;
            } elseif ($field === 'status') {
                if (!is_string($value)) {
                    throw new SpecialException('INVALID_ARGUMENT', 'status must be a string.', 422);
                }
                $value = special_validate_status($value);
            } elseif ($field === 'visibility') {
                if (!is_string($value)) {
                    throw new SpecialException('INVALID_ARGUMENT', 'visibility must be a string.', 422);
                }
                $value = special_validate_visibility($value);
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /** @param array<string, mixed> $fields @param list<string> $allowed */
    private function rejectUnknownFields(array $fields, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($fields), $allowed));
        if ($unknown !== []) {
            throw new SpecialException('INVALID_ARGUMENT', 'Unknown field: ' . (string) $unknown[0] . '.', 422);
        }
    }

    /** @param array<string, mixed> $filters @return array{query: ?string, project_name: ?string, status: ?string} */
    private function normalizeFilters(array $filters): array
    {
        $this->rejectUnknownFields($filters, ['query', 'project_name', 'status']);
        $normalized = ['query' => null, 'project_name' => null, 'status' => null];
        foreach (['query', 'project_name'] as $field) {
            if (!array_key_exists($field, $filters) || $filters[$field] === null) {
                continue;
            }
            if (!is_string($filters[$field])) {
                throw new SpecialException('INVALID_ARGUMENT', $field . ' must be a string.', 422);
            }
            $value = trim($filters[$field]);
            $length = special_string_length($value);
            if ($length === null || $length > 200) {
                throw new SpecialException('INVALID_ARGUMENT', $field . ' must be at most 200 characters.', 422);
            }
            $normalized[$field] = $value === '' ? null : $value;
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null) {
            if (!is_string($filters['status'])) {
                throw new SpecialException('INVALID_ARGUMENT', 'status must be a string.', 422);
            }
            $normalized['status'] = special_validate_status($filters['status']);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $row @param array{query: ?string, project_name: ?string, status: ?string} $filters */
    private function matches(array $row, array $filters): bool
    {
        if ($filters['status'] !== null && $row['status'] !== $filters['status']) {
            return false;
        }
        if ($filters['project_name'] !== null && $this->fold((string) $row['project_name']) !== $this->fold($filters['project_name'])) {
            return false;
        }
        if ($filters['query'] !== null) {
            $needle = $this->fold($filters['query']);
            foreach (['title', 'description', 'project_name'] as $field) {
                if (str_contains($this->fold((string) ($row[$field] ?? '')), $needle)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    private function fold(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function validateLimit(int $limit): int
    {
        $max = (int) $this->config['mcp_max_page_size'];
        if ($limit < 1 || $limit > $max) {
            throw new SpecialException('INVALID_ARGUMENT', "limit must be between 1 and {$max}.", 422);
        }
        return $limit;
    }

    private function validateId(int $specId): int
    {
        if ($specId < 1) {
            throw new SpecialException('INVALID_ARGUMENT', 'id must be a positive integer.', 422);
        }
        return $specId;
    }

    private function shareSigningKey(): string
    {
        return hash_hmac('sha256', 'special:spec-share:v1', (string) $this->config['app_key'], true);
    }

    private function decodeShareToken(string $token): int
    {
        if ($token === ''
            || strlen($token) > self::SHARE_TOKEN_MAX_LENGTH
            || preg_match('/\A[A-Za-z0-9_-]+\.[a-f0-9]{64}\z/', $token) !== 1) {
            throw new SpecialException('NOT_FOUND', 'Shared spec not found.', 404);
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);
        $payload = special_b64url_decode($encodedPayload);
        if ($payload === null || !hash_equals(hash_hmac('sha256', $payload, $this->shareSigningKey()), $signature)) {
            throw new SpecialException('NOT_FOUND', 'Shared spec not found.', 404);
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)
            || ($decoded['v'] ?? null) !== self::SHARE_TOKEN_VERSION
            || !is_int($decoded['id'] ?? null)
            || $decoded['id'] < 1) {
            throw new SpecialException('NOT_FOUND', 'Shared spec not found.', 404);
        }

        return $decoded['id'];
    }

    /** @param array{query: ?string, project_name: ?string, status: ?string} $filters */
    private function encodeCursor(string $updatedAt, int $id, array $filters): string
    {
        $payload = json_encode([
            'v' => 1,
            'updated_at' => $updatedAt,
            'id' => $id,
            'filter' => $this->filterFingerprint($filters),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signed = $payload . '.' . hash_hmac('sha256', $payload, (string) $this->config['app_key']);
        return special_b64url_encode($signed);
    }

    /** @param array{query: ?string, project_name: ?string, status: ?string} $filters @return array{updated_at: string, id: int}|null */
    private function decodeCursor(?string $cursor, array $filters): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (strlen($cursor) > 512) {
            throw new SpecialException('INVALID_ARGUMENT', 'cursor is too long.', 422);
        }
        $raw = special_b64url_decode($cursor);
        if ($raw === null) {
            throw new SpecialException('INVALID_ARGUMENT', 'cursor is invalid.', 422);
        }
        $separator = strrpos($raw, '.');
        if ($separator === false) {
            throw new SpecialException('INVALID_ARGUMENT', 'cursor is invalid.', 422);
        }
        $payload = substr($raw, 0, $separator);
        $signature = substr($raw, $separator + 1);
        $expected = hash_hmac('sha256', $payload, (string) $this->config['app_key']);
        if (!hash_equals($expected, $signature)) {
            throw new SpecialException('INVALID_ARGUMENT', 'cursor is invalid.', 422);
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)
            || (int) ($decoded['v'] ?? 0) !== 1
            || (string) ($decoded['filter'] ?? '') !== $this->filterFingerprint($filters)
            || !is_string($decoded['updated_at'] ?? null)
            || !is_int($decoded['id'] ?? null)
            || $decoded['id'] < 1
        ) {
            throw new SpecialException('INVALID_ARGUMENT', 'cursor is invalid.', 422);
        }

        return ['updated_at' => $decoded['updated_at'], 'id' => $decoded['id']];
    }

    /** @param array{query: ?string, project_name: ?string, status: ?string} $filters */
    private function filterFingerprint(array $filters): string
    {
        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function author(int $userId): ?string
    {
        if (!array_key_exists($userId, $this->authors)) {
            $this->authors[$userId] = $this->security->usernameFor($userId);
        }

        return $this->authors[$userId];
    }

    /** @param array<string, mixed> $row */
    private function summary(array $row, Principal $viewer): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'project_name' => (string) $row['project_name'],
            'status' => (string) $row['status'],
            'visibility' => (string) $row['visibility'],
            'revision' => (int) $row['revision'],
            'author' => $this->author((int) $row['created_by']),
            'is_owner' => (int) $row['created_by'] === $viewer->id,
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatSpec(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'body' => $row['body'] === null ? null : (string) $row['body'],
            'project_name' => (string) $row['project_name'],
            'status' => (string) $row['status'],
            'visibility' => (string) $row['visibility'],
            'revision' => (int) $row['revision'],
            'created_by' => (int) $row['created_by'],
            'author' => $this->author((int) $row['created_by']),
            'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => $row['deleted_at'] === null ? null : (string) $row['deleted_at'],
        ];
    }
}
