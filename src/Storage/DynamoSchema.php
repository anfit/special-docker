<?php

declare(strict_types=1);

namespace Special\Storage;

/**
 * Table definition shared by scripts/init-table.php (local DynamoDB) and kept in sync by hand with
 * deploy/aws/special-stack.yaml (AWS). See docs/DYNAMODB.md for the access patterns behind it.
 */
final class DynamoSchema
{
    public const TTL_ATTRIBUTE = 'ttl';

    /** At most 20 non-key attributes for DynamoDB INCLUDE projections. The spec body and unused etype are omitted. */
    public const PROJECTED_ATTRIBUTES = [
        'id', 'title', 'description', 'project_name', 'status', 'visibility', 'revision',
        'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        'username', 'role', 'enabled', 'version', 'last_login_at',
        'user_id', 'revoked_at', 'consumed_at',
    ];

    /** @return array<string, mixed> arguments for DynamoDbClient::createTable() */
    public static function createTableArguments(string $table): array
    {
        $projection = ['ProjectionType' => 'INCLUDE', 'NonKeyAttributes' => self::PROJECTED_ATTRIBUTES];

        return [
            'TableName' => $table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [
                ['AttributeName' => 'pk', 'AttributeType' => 'S'],
                ['AttributeName' => 'sk', 'AttributeType' => 'S'],
                ['AttributeName' => 'gsi1pk', 'AttributeType' => 'S'],
                ['AttributeName' => 'gsi1sk', 'AttributeType' => 'S'],
                ['AttributeName' => 'gsi2pk', 'AttributeType' => 'S'],
                ['AttributeName' => 'gsi2sk', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => 'pk', 'KeyType' => 'HASH'],
                ['AttributeName' => 'sk', 'KeyType' => 'RANGE'],
            ],
            'GlobalSecondaryIndexes' => [
                [
                    'IndexName' => 'gsi1',
                    'KeySchema' => [
                        ['AttributeName' => 'gsi1pk', 'KeyType' => 'HASH'],
                        ['AttributeName' => 'gsi1sk', 'KeyType' => 'RANGE'],
                    ],
                    'Projection' => $projection,
                ],
                [
                    'IndexName' => 'gsi2',
                    'KeySchema' => [
                        ['AttributeName' => 'gsi2pk', 'KeyType' => 'HASH'],
                        ['AttributeName' => 'gsi2sk', 'KeyType' => 'RANGE'],
                    ],
                    'Projection' => $projection,
                ],
            ],
        ];
    }
}
