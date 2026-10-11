<?php

declare(strict_types=1);

namespace Special\Storage;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Special\SpecialException;

/**
 * DynamoDB implementation of Store (single table, two GSIs; see docs/DYNAMODB.md).
 *
 * Key layout
 *   USER#<id>/META            user               gsi1: USERS / <lower username>#<id>
 *   USERNAME#<lower>/META     username lock      (uniqueness)
 *   SPEC#<id>/META            spec               gsi1: SPECS#PUBLIC / <updated_at>#<id>   (public, live)
 *                                                gsi2: SPECS#PRIVATE#<owner> / same       (private, live)
 *   COUNTER#<name>/META       atomic counters    (spec ids, user ids, enabled administrators)
 *   BOOTSTRAP#admin/META      one-shot marker that closes first-admin bootstrap
 *   AUDIT#<type>#<id>/<ts>#<rand>
 *   LOGIN#<key>/META          login throttling   (TTL)
 *   REMEMBER#<selector>/META  remember-me token  gsi1: CRED#<user> / RM#<selector>   (TTL)
 *   OCLIENT#<client_id>/META  OAuth client
 *   OCODE#<hash>/META         authorization code gsi1: CRED#<user> / CODE#<hash>      (TTL)
 *   OAT#<hash>/META           access token       gsi1: CRED#<user> / AT#<hash>, gsi2: FAMILY#<fam> / AT#<hash> (TTL)
 *   ORT#<hash>/META           refresh token      gsi1: CRED#<user> / RT#<hash>, gsi2: FAMILY#<fam> / RT#<hash> (TTL)
 */
final class DynamoStore implements Store
{
    private const SK = 'META';
    private const GRACE_SECONDS = 86400;

    private Marshaler $marshaler;

    public function __construct(private DynamoDbClient $client, private string $table)
    {
        $this->marshaler = new Marshaler();
    }

    // ------------------------------------------------------------------ infra

    public function client(): DynamoDbClient
    {
        return $this->client;
    }

    public function ping(): void
    {
        $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('COUNTER#spec'),
            'ProjectionExpression' => 'pk',
        ]);
    }

    /** @return array<string, mixed> */
    private function key(string $pk, string $sk = self::SK): array
    {
        return ['pk' => ['S' => $pk], 'sk' => ['S' => $sk]];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function marshalItem(array $attributes): array
    {
        // Absent and empty are the same thing here: no NULL-typed attributes (they defeat attribute_not_exists
        // conditions) and no empty strings (not every SDK/emulator version accepts them).
        return $this->marshaler->marshalItem(array_filter($attributes, static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function unmarshal(array $item): array
    {
        $row = $this->marshaler->unmarshalItem($item);
        unset($row['pk'], $row['sk'], $row['gsi1pk'], $row['gsi1sk'], $row['gsi2pk'], $row['gsi2sk'], $row['ttl'], $row['etype']);

        return $row;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options condition, names (alias => attribute), values (alias => value)
     * @return array<string, mixed> TransactWriteItems entry
     */
    private function put(array $attributes, array $options = []): array
    {
        $put = ['TableName' => $this->table, 'Item' => $this->marshalItem($attributes)];

        return ['Put' => $put + $this->expression($options)];
    }

    /**
     * @param array<string, mixed> $assignments attribute => value (null removes the attribute)
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function update(string $pk, array $assignments, array $options = [], string $add = ''): array
    {
        $names = $options['names'] ?? [];
        $values = $options['values'] ?? [];
        $set = [];
        $remove = [];
        $index = 0;
        foreach ($assignments as $attribute => $value) {
            $name = '#u' . $index;
            $names[$name] = $attribute;
            if ($value === null) {
                $remove[] = $name;
            } else {
                $values[':u' . $index] = $value;
                $set[] = $name . ' = :u' . $index;
            }
            $index++;
        }
        $parts = [];
        if ($set !== []) {
            $parts[] = 'SET ' . implode(', ', $set);
        }
        if ($remove !== []) {
            $parts[] = 'REMOVE ' . implode(', ', $remove);
        }
        if ($add !== '') {
            $parts[] = $add;
        }
        $options['names'] = $names;
        $options['values'] = $values;

        return ['Update' => [
            'TableName' => $this->table,
            'Key' => $this->key($pk),
            'UpdateExpression' => implode(' ', $parts),
        ] + $this->expression($options)];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function expression(array $options): array
    {
        $out = [];
        if (isset($options['condition'])) {
            $out['ConditionExpression'] = $options['condition'];
        }
        if (($options['names'] ?? []) !== []) {
            $out['ExpressionAttributeNames'] = $options['names'];
        }
        if (($options['values'] ?? []) !== []) {
            $out['ExpressionAttributeValues'] = $this->marshaler->marshalItem($options['values']);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>|null cancellation reason codes (index-aligned with $items) or null on success
     */
    private function transact(array $items): ?array
    {
        try {
            $this->client->transactWriteItems(['TransactItems' => $items]);
            return null;
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() !== 'TransactionCanceledException') {
                throw $exception;
            }
            $codes = [];
            $reasons = $exception->get('CancellationReasons');
            if (!is_array($reasons)) {
                $response = $exception->getResponse();
                if ($response !== null) {
                    $body = $response->getBody();
                    $body->rewind();
                    $decoded = json_decode((string) $body, true);
                    $reasons = is_array($decoded) ? ($decoded['CancellationReasons'] ?? []) : [];
                }
            }
            foreach ((array) $reasons as $reason) {
                $codes[] = (string) ($reason['Code'] ?? 'None');
            }
            if ($codes === []) {
                throw $exception;
            }

            return $codes;
        }
    }

    private function conditionFailed(?array $reasons, int $index): bool
    {
        return $reasons !== null && ($reasons[$index] ?? 'None') === 'ConditionalCheckFailed';
    }

    /** @param array<string, mixed> $options @return bool false when the condition failed */
    private function guardedCall(callable $call): bool
    {
        try {
            $call();
            return true;
        } catch (DynamoDbException $exception) {
            if ($exception->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                return false;
            }
            throw $exception;
        }
    }

    private function nextId(string $counter): int
    {
        $result = $this->client->updateItem([
            'TableName' => $this->table,
            'Key' => $this->key('COUNTER#' . $counter),
            'UpdateExpression' => 'ADD #n :one',
            'ExpressionAttributeNames' => ['#n' => 'n'],
            'ExpressionAttributeValues' => [':one' => ['N' => '1']],
            'ReturnValues' => 'UPDATED_NEW',
        ]);

        return (int) $result['Attributes']['n']['N'];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    private function queryAll(string $index, string $partition, bool $forward = true): array
    {
        $partitionAttr = $index === 'gsi2' ? 'gsi2pk' : 'gsi1pk';
        $items = [];
        $start = null;
        do {
            $arguments = [
                'TableName' => $this->table,
                'IndexName' => $index,
                'KeyConditionExpression' => '#p = :p',
                'ExpressionAttributeNames' => ['#p' => $partitionAttr],
                'ExpressionAttributeValues' => [':p' => ['S' => $partition]],
                'ScanIndexForward' => $forward,
            ];
            if ($start !== null) {
                $arguments['ExclusiveStartKey'] = $start;
            }
            $result = $this->client->query($arguments);
            foreach ($result['Items'] as $item) {
                $items[] = $item;
            }
            $start = $result['LastEvaluatedKey'] ?? null;
        } while ($start !== null);

        return $items;
    }

    private function epoch(?string $iso, int $fallbackSeconds = 0): int
    {
        if ($iso === null) {
            return time() + $fallbackSeconds;
        }
        try {
            return (new \DateTimeImmutable($iso))->getTimestamp();
        } catch (\Exception) {
            return time() + $fallbackSeconds;
        }
    }

    // ------------------------------------------------------------------ audit

    /**
     * @param array<string, mixed> $audit
     * @return array<string, mixed>
     */
    private function auditAttributes(array $audit): array
    {
        $entityId = $audit['entity_id'] ?? null;
        $createdAt = (string) ($audit['created_at'] ?? \Special\special_now());

        return [
            'pk' => 'AUDIT#' . $audit['entity_type'] . '#' . ($entityId ?? 0),
            'sk' => $createdAt . '#' . bin2hex(random_bytes(4)),
            'etype' => 'audit',
            'action' => (string) $audit['action'],
            'entity_type' => (string) $audit['entity_type'],
            'entity_id' => $entityId,
            'actor_user_id' => $audit['actor_user_id'] ?? null,
            'metadata_json' => json_encode((object) ($audit['metadata'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'request_id' => $audit['request_id'] ?? null,
            'client_id' => $audit['client_id'] ?? null,
            'created_at' => $createdAt,
        ];
    }

    public function writeAudit(array $audit): void
    {
        $this->client->putItem(['TableName' => $this->table, 'Item' => $this->marshalItem($this->auditAttributes($audit))]);
    }

    public function listAudit(string $entityType, ?int $entityId, int $limit = 50): array
    {
        $result = $this->client->query([
            'TableName' => $this->table,
            'KeyConditionExpression' => '#p = :p',
            'ExpressionAttributeNames' => ['#p' => 'pk'],
            'ExpressionAttributeValues' => [':p' => ['S' => 'AUDIT#' . $entityType . '#' . ($entityId ?? 0)]],
            'ScanIndexForward' => false,
            'Limit' => $limit,
            'ConsistentRead' => true,
        ]);
        $rows = [];
        foreach ($result['Items'] as $item) {
            $row = $this->unmarshal($item);
            $row['metadata'] = json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [];
            unset($row['metadata_json']);
            $row['entity_id'] = isset($row['entity_id']) ? (int) $row['entity_id'] : null;
            $row['actor_user_id'] = isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null;
            $row += ['request_id' => null, 'client_id' => null];
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $audit @return array<string, mixed> */
    private function putAudit(array $audit): array
    {
        return $this->put($this->auditAttributes($audit));
    }

    // ------------------------------------------------------------------ users

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function userRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password_hash' => (string) ($row['password_hash'] ?? ''),
            'role' => (string) $row['role'],
            'enabled' => (bool) $row['enabled'],
            'version' => (int) ($row['version'] ?? 1),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'last_login_at' => isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        ];
    }

    public function createUser(array $user, array $audit, bool $firstAdmin = false): array
    {
        $adminEnabled = $user['role'] === 'admin' && ($user['enabled'] ?? true);
        if ($firstAdmin && $this->enabledAdminCount() > 0) {
            throw new SpecialException('CONFLICT', 'An administrator already exists.', 409);
        }
        $id = $this->nextId('user');
        $lower = strtolower((string) $user['username']);
        $now = (string) $user['created_at'];
        $attributes = [
            'pk' => 'USER#' . $id,
            'sk' => self::SK,
            'etype' => 'user',
            'id' => $id,
            'username' => (string) $user['username'],
            'password_hash' => (string) $user['password_hash'],
            'role' => (string) $user['role'],
            'enabled' => (bool) ($user['enabled'] ?? true),
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'gsi1pk' => 'USERS',
            'gsi1sk' => $lower . '#' . sprintf('%012d', $id),
        ];
        $items = [
            $this->put($attributes, ['condition' => 'attribute_not_exists(pk)']),
            $this->put(
                ['pk' => 'USERNAME#' . $lower, 'sk' => self::SK, 'etype' => 'username', 'user_id' => $id],
                ['condition' => 'attribute_not_exists(pk)'],
            ),
        ];
        $lockIndex = 1;
        $markerIndex = null;
        if ($adminEnabled) {
            $items[] = $this->update('COUNTER#enabled_admins', [], ['names' => ['#n' => 'n'], 'values' => [':one' => 1]], 'ADD #n :one');
        }
        if ($firstAdmin) {
            $markerIndex = count($items);
            $items[] = $this->put(
                ['pk' => 'BOOTSTRAP#admin', 'sk' => self::SK, 'etype' => 'bootstrap', 'user_id' => $id],
                ['condition' => 'attribute_not_exists(pk)'],
            );
        }
        $audit['entity_id'] = $id;
        $items[] = $this->putAudit($audit);

        $reasons = $this->transact($items);
        if ($reasons !== null) {
            if ($this->conditionFailed($reasons, $lockIndex)) {
                throw new SpecialException('INVALID_ARGUMENT', 'That username is already in use.', 422);
            }
            if ($markerIndex !== null && $this->conditionFailed($reasons, $markerIndex)) {
                throw new SpecialException('CONFLICT', 'An administrator already exists.', 409);
            }
            throw new SpecialException('INTERNAL_ERROR', 'User creation failed.', 500);
        }

        return $this->userRow($attributes);
    }

    private function enabledAdminCount(): int
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('COUNTER#enabled_admins'),
            'ConsistentRead' => true,
        ]);

        return isset($result['Item']['n']['N']) ? (int) $result['Item']['n']['N'] : 0;
    }

    public function getUser(int $id): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('USER#' . $id),
            'ConsistentRead' => true,
        ]);

        return empty($result['Item']) ? null : $this->userRow($this->unmarshal($result['Item']));
    }

    public function findUserByUsername(string $normalizedUsername): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('USERNAME#' . strtolower($normalizedUsername)),
            'ConsistentRead' => true,
        ]);
        if (empty($result['Item']['user_id']['N'])) {
            return null;
        }

        return $this->getUser((int) $result['Item']['user_id']['N']);
    }

    public function listUsers(): array
    {
        return array_map(
            fn (array $item): array => $this->userRow($this->unmarshal($item)),
            $this->queryAll('gsi1', 'USERS'),
        );
    }

    public function updateUser(int $id, array $changes, int $expectedVersion, array $audit, int $enabledAdminDelta = 0): array
    {
        $options = [
            'condition' => 'attribute_exists(pk) AND #ver = :expected',
            'names' => ['#ver' => 'version'],
            'values' => [':expected' => $expectedVersion, ':one' => 1],
        ];
        $items = [$this->update('USER#' . $id, $changes, $options, 'ADD #ver :one')];
        $counterIndex = null;
        if ($enabledAdminDelta !== 0) {
            $counterIndex = count($items);
            $counterOptions = ['names' => ['#n' => 'n'], 'values' => [':d' => $enabledAdminDelta]];
            if ($enabledAdminDelta < 0) {
                $counterOptions['condition'] = '#n >= :min';
                $counterOptions['values'][':min'] = 1 - $enabledAdminDelta;
            }
            $items[] = $this->update('COUNTER#enabled_admins', [], $counterOptions, 'ADD #n :d');
        }
        $audit['entity_id'] = $id;
        $items[] = $this->putAudit($audit);

        $reasons = $this->transact($items);
        if ($reasons !== null) {
            if ($counterIndex !== null && $this->conditionFailed($reasons, $counterIndex)) {
                throw new SpecialException('CONFLICT', 'The final enabled administrator cannot be disabled or demoted.', 409, ['reason' => 'last_admin']);
            }
            if ($this->conditionFailed($reasons, 0)) {
                if ($this->getUser($id) === null) {
                    throw new SpecialException('NOT_FOUND', 'User not found.', 404);
                }
                throw new SpecialException('CONFLICT', 'The user was changed by another request.', 409, ['reason' => 'stale']);
            }
            throw new SpecialException('INTERNAL_ERROR', 'The user could not be updated.', 500);
        }

        $updated = $this->getUser($id);
        if ($updated === null) {
            throw new SpecialException('NOT_FOUND', 'User not found.', 404);
        }

        return $updated;
    }

    public function recordLogin(int $id, string $at, array $audit): void
    {
        $audit['entity_id'] = $id;
        $this->transact([
            $this->update('USER#' . $id, ['last_login_at' => $at], ['condition' => 'attribute_exists(pk)']),
            $this->putAudit($audit),
        ]);
    }

    // ------------------------------------------------------------------ specs

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function specAttributes(array $spec): array
    {
        $attributes = [
            'pk' => 'SPEC#' . (int) $spec['id'],
            'sk' => self::SK,
            'etype' => 'spec',
            'id' => (int) $spec['id'],
            'title' => $spec['title'],
            'description' => $spec['description'] ?? null,
            'body' => $spec['body'] ?? null,
            'project_name' => $spec['project_name'],
            'status' => $spec['status'],
            'visibility' => $spec['visibility'],
            'revision' => (int) $spec['revision'],
            'created_by' => (int) $spec['created_by'],
            'updated_by' => (int) $spec['updated_by'],
            'created_at' => $spec['created_at'],
            'updated_at' => $spec['updated_at'],
            'deleted_at' => $spec['deleted_at'] ?? null,
        ];
        if (($spec['deleted_at'] ?? null) === null) {
            $key = \Special\special_spec_key((string) $spec['updated_at'], (int) $spec['id']);
            if ($spec['visibility'] === 'public') {
                $attributes['gsi1pk'] = 'SPECS#PUBLIC';
                $attributes['gsi1sk'] = $key;
            } else {
                $attributes['gsi2pk'] = 'SPECS#PRIVATE#' . (int) $spec['created_by'];
                $attributes['gsi2sk'] = $key;
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function specRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'body' => isset($row['body']) ? (string) $row['body'] : null,
            'project_name' => (string) $row['project_name'],
            'status' => (string) $row['status'],
            'visibility' => (string) ($row['visibility'] ?? 'public'),
            'revision' => (int) $row['revision'],
            'created_by' => (int) $row['created_by'],
            'updated_by' => (int) $row['updated_by'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
        ];
    }

    public function createSpec(array $spec, array $audit): array
    {
        $id = $this->nextId('spec');
        $spec = array_merge($spec, ['id' => $id, 'revision' => 1, 'deleted_at' => null]);
        $audit['entity_id'] = $id;
        $reasons = $this->transact([
            $this->put($this->specAttributes($spec), ['condition' => 'attribute_not_exists(pk)']),
            $this->putAudit($audit),
        ]);
        if ($reasons !== null) {
            throw new SpecialException('INTERNAL_ERROR', 'Spec creation failed.', 500);
        }

        return $this->specRow($this->specAttributes($spec));
    }

    public function getSpec(int $id): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('SPEC#' . $id),
            'ConsistentRead' => true,
        ]);

        return empty($result['Item']) ? null : $this->specRow($this->unmarshal($result['Item']));
    }

    public function replaceSpec(array $spec, int $expectedRevision, array $audit): bool
    {
        $audit['entity_id'] = (int) $spec['id'];
        $reasons = $this->transact([
            $this->put($this->specAttributes($spec), [
                'condition' => 'attribute_exists(pk) AND #rev = :expected AND attribute_not_exists(deleted_at)',
                'names' => ['#rev' => 'revision'],
                'values' => [':expected' => $expectedRevision],
            ]),
            $this->putAudit($audit),
        ]);
        if ($reasons === null) {
            return true;
        }
        if ($this->conditionFailed($reasons, 0)) {
            return false;
        }
        throw new SpecialException('INTERNAL_ERROR', 'The spec could not be saved.', 500);
    }

    public function querySpecs(string $stream, ?string $before, int $limit): array
    {
        if ($stream === 'public') {
            $index = 'gsi1';
            $partition = 'SPECS#PUBLIC';
        } elseif (preg_match('/^private:([1-9][0-9]*)$/D', $stream, $matches) === 1) {
            $index = 'gsi2';
            $partition = 'SPECS#PRIVATE#' . $matches[1];
        } else {
            throw new \InvalidArgumentException('Unknown spec stream.');
        }
        $partitionAttr = $index . 'pk';
        $sortAttr = $index . 'sk';
        $names = ['#p' => $partitionAttr];
        $values = [':p' => ['S' => $partition]];
        $condition = '#p = :p';
        if ($before !== null) {
            $names['#s'] = $sortAttr;
            $values[':b'] = ['S' => $before];
            $condition .= ' AND #s < :b';
        }
        $result = $this->client->query([
            'TableName' => $this->table,
            'IndexName' => $index,
            'KeyConditionExpression' => $condition,
            'ExpressionAttributeNames' => $names,
            'ExpressionAttributeValues' => $values,
            'ScanIndexForward' => false,
            'Limit' => max(1, $limit),
        ]);

        return array_map(fn (array $item): array => $this->specRow($this->unmarshal($item)), $result['Items']);
    }

    // ------------------------------------------------------ login throttling

    public function getLoginAttempt(string $key): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('LOGIN#' . $key),
            'ConsistentRead' => true,
        ]);
        if (empty($result['Item'])) {
            return null;
        }
        $row = $this->unmarshal($result['Item']);

        return [
            'window_started_at' => (string) $row['window_started_at'],
            'attempt_count' => (int) $row['attempt_count'],
            'blocked_until' => isset($row['blocked_until']) ? (string) $row['blocked_until'] : null,
            'version' => (int) ($row['version'] ?? 1),
        ];
    }

    public function putLoginAttempt(string $key, array $row, ?int $expectedVersion, int $ttlAt): bool
    {
        $attributes = [
            'pk' => 'LOGIN#' . $key,
            'sk' => self::SK,
            'etype' => 'login',
            'window_started_at' => $row['window_started_at'],
            'attempt_count' => (int) $row['attempt_count'],
            'blocked_until' => $row['blocked_until'] ?? null,
            'version' => $expectedVersion === null ? 1 : $expectedVersion + 1,
            'ttl' => $ttlAt,
        ];
        $options = $expectedVersion === null
            ? ['condition' => 'attribute_not_exists(pk)']
            : ['condition' => '#v = :expected', 'names' => ['#v' => 'version'], 'values' => [':expected' => $expectedVersion]];
        $put = $this->put($attributes, $options)['Put'];

        return $this->guardedCall(fn () => $this->client->putItem($put));
    }

    public function deleteLoginAttempt(string $key): void
    {
        $this->client->deleteItem(['TableName' => $this->table, 'Key' => $this->key('LOGIN#' . $key)]);
    }

    // ------------------------------------------------------ remember-me

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function rememberAttributes(array $row): array
    {
        return [
            'pk' => 'REMEMBER#' . $row['selector'],
            'sk' => self::SK,
            'etype' => 'remember',
            'selector' => $row['selector'],
            'user_id' => (int) $row['user_id'],
            'token_hash' => $row['token_hash'],
            'expires_at' => $row['expires_at'],
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'] ?? null,
            'gsi1pk' => 'CRED#' . (int) $row['user_id'],
            'gsi1sk' => 'RM#' . $row['selector'],
            'ttl' => $this->epoch((string) $row['expires_at']) + self::GRACE_SECONDS,
        ];
    }

    public function putRemember(array $row): void
    {
        $this->client->putItem(['TableName' => $this->table, 'Item' => $this->marshalItem($this->rememberAttributes($row))]);
    }

    public function getRemember(string $selector): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('REMEMBER#' . $selector),
            'ConsistentRead' => true,
        ]);
        if (empty($result['Item'])) {
            return null;
        }
        $row = $this->unmarshal($result['Item']);
        unset($row['gsi1pk'], $row['gsi1sk']);

        return [
            'selector' => (string) $row['selector'],
            'user_id' => (int) $row['user_id'],
            'token_hash' => (string) $row['token_hash'],
            'expires_at' => (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
            'last_used_at' => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
        ];
    }

    public function rotateRemember(string $oldSelector, string $expectedHash, array $newRow): bool
    {
        $reasons = $this->transact([
            ['Delete' => [
                'TableName' => $this->table,
                'Key' => $this->key('REMEMBER#' . $oldSelector),
                'ConditionExpression' => '#h = :h',
                'ExpressionAttributeNames' => ['#h' => 'token_hash'],
                'ExpressionAttributeValues' => [':h' => ['S' => $expectedHash]],
            ]],
            $this->put($this->rememberAttributes($newRow)),
        ]);
        if ($reasons === null) {
            return true;
        }
        if ($this->conditionFailed($reasons, 0)) {
            return false;
        }
        throw new SpecialException('INTERNAL_ERROR', 'The remember token could not be rotated.', 500);
    }

    public function deleteRemember(string $selector): void
    {
        $this->client->deleteItem(['TableName' => $this->table, 'Key' => $this->key('REMEMBER#' . $selector)]);
    }

    // ---------------------------------------------------------------- OAuth

    public function getOAuthClient(string $clientId): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('OCLIENT#' . $clientId),
            'ConsistentRead' => true,
        ]);
        if (empty($result['Item'])) {
            return null;
        }
        $row = $this->unmarshal($result['Item']);

        return [
            'client_id' => (string) $row['client_id'],
            'client_name' => (string) $row['client_name'],
            'client_type' => (string) $row['client_type'],
            'client_secret_hash' => isset($row['client_secret_hash']) ? (string) $row['client_secret_hash'] : null,
            'redirect_uris' => (string) $row['redirect_uris'],
            'enabled' => (bool) $row['enabled'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function putOAuthClient(array $client, bool $overwrite): bool
    {
        $attributes = [
            'pk' => 'OCLIENT#' . $client['client_id'],
            'sk' => self::SK,
            'etype' => 'oauth_client',
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'client_type' => $client['client_type'],
            'client_secret_hash' => $client['client_secret_hash'] ?? null,
            'redirect_uris' => $client['redirect_uris'],
            'enabled' => (bool) ($client['enabled'] ?? true),
            'created_at' => $client['created_at'],
        ];
        $put = $this->put($attributes, $overwrite ? [] : ['condition' => 'attribute_not_exists(pk)'])['Put'];

        return $this->guardedCall(fn () => $this->client->putItem($put));
    }

    /** @param array<string, mixed> $code @return array<string, mixed> */
    private function codeAttributes(array $code): array
    {
        return [
            'pk' => 'OCODE#' . $code['code_hash'],
            'sk' => self::SK,
            'etype' => 'oauth_code',
            'code_hash' => $code['code_hash'],
            'client_id' => $code['client_id'],
            'user_id' => (int) $code['user_id'],
            'redirect_uri' => $code['redirect_uri'],
            'resource' => $code['resource'] ?? null,
            'scope' => $code['scope'],
            'code_challenge' => $code['code_challenge'],
            'code_challenge_method' => $code['code_challenge_method'],
            'expires_at' => $code['expires_at'],
            'consumed_at' => $code['consumed_at'] ?? null,
            'created_at' => $code['created_at'],
            'gsi1pk' => 'CRED#' . (int) $code['user_id'],
            'gsi1sk' => 'CODE#' . $code['code_hash'],
            'ttl' => $this->epoch((string) $code['expires_at']) + self::GRACE_SECONDS,
        ];
    }

    public function putAuthCode(array $code, array $audit): void
    {
        $this->transact([$this->put($this->codeAttributes($code)), $this->putAudit($audit)]);
    }

    public function getAuthCode(string $codeHash): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key('OCODE#' . $codeHash),
            'ConsistentRead' => true,
        ]);
        if (empty($result['Item'])) {
            return null;
        }
        $row = $this->unmarshal($result['Item']);
        unset($row['gsi1pk'], $row['gsi1sk']);

        return [
            'code_hash' => (string) $row['code_hash'],
            'client_id' => (string) $row['client_id'],
            'user_id' => (int) $row['user_id'],
            'redirect_uri' => (string) $row['redirect_uri'],
            'resource' => isset($row['resource']) ? (string) $row['resource'] : null,
            'scope' => (string) $row['scope'],
            'code_challenge' => (string) $row['code_challenge'],
            'code_challenge_method' => (string) $row['code_challenge_method'],
            'expires_at' => (string) $row['expires_at'],
            'consumed_at' => isset($row['consumed_at']) ? (string) $row['consumed_at'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function consumeAuthCode(string $codeHash, string $at): bool
    {
        $update = $this->update(
            'OCODE#' . $codeHash,
            ['consumed_at' => $at],
            ['condition' => 'attribute_exists(pk) AND attribute_not_exists(consumed_at)'],
        )['Update'];

        return $this->guardedCall(fn () => $this->client->updateItem($update));
    }

    /** @param array<string, mixed> $token @return array<string, mixed> */
    private function tokenAttributes(string $prefix, string $short, array $token): array
    {
        $attributes = [
            'pk' => $prefix . '#' . $token['token_hash'],
            'sk' => self::SK,
            'etype' => $short === 'AT' ? 'oauth_access' : 'oauth_refresh',
            'token_hash' => $token['token_hash'],
            'client_id' => $token['client_id'],
            'user_id' => (int) $token['user_id'],
            'resource' => $token['resource'] ?? null,
            'refresh_family' => $token['refresh_family'] ?? null,
            'scope' => $token['scope'],
            'expires_at' => $token['expires_at'],
            'revoked_at' => $token['revoked_at'] ?? null,
            'replaced_by_hash' => $token['replaced_by_hash'] ?? null,
            'last_used_at' => $token['last_used_at'] ?? null,
            'created_at' => $token['created_at'],
            'gsi1pk' => 'CRED#' . (int) $token['user_id'],
            'gsi1sk' => $short . '#' . $token['token_hash'],
            'ttl' => $this->epoch((string) $token['expires_at']) + self::GRACE_SECONDS,
        ];
        if (($token['refresh_family'] ?? null) !== null) {
            $attributes['gsi2pk'] = 'FAMILY#' . $token['refresh_family'];
            $attributes['gsi2sk'] = $short . '#' . $token['token_hash'];
        }

        return $attributes;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function tokenRow(array $row): array
    {
        return [
            'token_hash' => (string) $row['token_hash'],
            'client_id' => (string) $row['client_id'],
            'user_id' => (int) $row['user_id'],
            'resource' => isset($row['resource']) ? (string) $row['resource'] : null,
            'refresh_family' => isset($row['refresh_family']) ? (string) $row['refresh_family'] : null,
            'scope' => (string) $row['scope'],
            'expires_at' => (string) $row['expires_at'],
            'revoked_at' => isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
            'replaced_by_hash' => isset($row['replaced_by_hash']) ? (string) $row['replaced_by_hash'] : null,
            'last_used_at' => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function issueTokens(array $access, array $refresh, array $audit): void
    {
        $this->transact([
            $this->put($this->tokenAttributes('OAT', 'AT', $access)),
            $this->put($this->tokenAttributes('ORT', 'RT', $refresh)),
            $this->putAudit($audit),
        ]);
    }

    public function getAccessToken(string $tokenHash): ?array
    {
        return $this->getToken('OAT#' . $tokenHash);
    }

    public function getRefreshToken(string $tokenHash): ?array
    {
        return $this->getToken('ORT#' . $tokenHash);
    }

    /** @return array<string, mixed>|null */
    private function getToken(string $pk): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => $this->key($pk),
            'ConsistentRead' => true,
        ]);

        return empty($result['Item']) ? null : $this->tokenRow($this->unmarshal($result['Item']));
    }

    public function rotateRefresh(string $oldHash, string $at, array $access, array $refresh, array $audit): bool
    {
        $reasons = $this->transact([
            $this->update(
                'ORT#' . $oldHash,
                ['revoked_at' => $at, 'last_used_at' => $at, 'replaced_by_hash' => (string) $refresh['token_hash']],
                ['condition' => 'attribute_exists(pk) AND attribute_not_exists(revoked_at)'],
            ),
            $this->put($this->tokenAttributes('OAT', 'AT', $access)),
            $this->put($this->tokenAttributes('ORT', 'RT', $refresh)),
            $this->putAudit($audit),
        ]);
        if ($reasons === null) {
            return true;
        }
        if ($this->conditionFailed($reasons, 0)) {
            return false;
        }
        throw new SpecialException('INTERNAL_ERROR', 'The refresh token could not be rotated.', 500);
    }

    public function revokeFamily(string $family, string $at): void
    {
        foreach ($this->queryAll('gsi2', 'FAMILY#' . $family) as $item) {
            $this->revokeItem($item['pk']['S'], $at);
        }
    }

    private function revokeItem(string $pk, string $at): void
    {
        $this->client->updateItem([
            'TableName' => $this->table,
            'Key' => $this->key($pk),
            'UpdateExpression' => 'SET #r = if_not_exists(#r, :at)',
            'ConditionExpression' => 'attribute_exists(pk)',
            'ExpressionAttributeNames' => ['#r' => 'revoked_at'],
            'ExpressionAttributeValues' => [':at' => ['S' => $at]],
        ]);
    }

    public function revokeUserCredentials(int $userId, string $at): void
    {
        foreach ($this->queryAll('gsi1', 'CRED#' . $userId) as $item) {
            $pk = $item['pk']['S'];
            $kind = strstr($item['gsi1sk']['S'], '#', true);
            try {
                if ($kind === 'AT' || $kind === 'RT') {
                    $this->revokeItem($pk, $at);
                } elseif ($kind === 'RM' || ($kind === 'CODE' && !isset($item['consumed_at']))) {
                    $this->client->deleteItem(['TableName' => $this->table, 'Key' => $this->key($pk)]);
                }
            } catch (DynamoDbException $exception) {
                // Item expired via TTL between the query and the write; nothing left to revoke.
                if ($exception->getAwsErrorCode() !== 'ConditionalCheckFailedException') {
                    throw $exception;
                }
            }
        }
    }
}
