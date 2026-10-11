<?php

declare(strict_types=1);

/**
 * Create the DynamoDB table (and enable TTL) if it does not exist.
 *
 * Intended for DynamoDB Local and first-time experiments. In AWS the table is created by
 * deploy/aws/special-stack.yaml; running this there is harmless but unnecessary.
 *
 *   php scripts/init-table.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Special\Storage\DynamoSchema;

try {
    $config = special_config();
    if ($config['store'] !== 'dynamodb') {
        fwrite(STDERR, "SPECIAL_STORE is not dynamodb; nothing to create.\n");
        exit(2);
    }
    $store = special_store($config);
    $client = $store->client();
    $table = (string) $config['dynamodb_table'];

    try {
        $client->describeTable(['TableName' => $table]);
        fwrite(STDOUT, "Table {$table} already exists.\n");
    } catch (Aws\DynamoDb\Exception\DynamoDbException $exception) {
        if ($exception->getAwsErrorCode() !== 'ResourceNotFoundException') {
            throw $exception;
        }
        $client->createTable(DynamoSchema::createTableArguments($table));
        $client->waitUntil('TableExists', ['TableName' => $table]);
        fwrite(STDOUT, "Created table {$table}.\n");
    }

    try {
        $client->updateTimeToLive([
            'TableName' => $table,
            'TimeToLiveSpecification' => ['Enabled' => true, 'AttributeName' => DynamoSchema::TTL_ATTRIBUTE],
        ]);
        fwrite(STDOUT, "Enabled TTL on attribute '" . DynamoSchema::TTL_ATTRIBUTE . "'.\n");
    } catch (Aws\DynamoDb\Exception\DynamoDbException $exception) {
        // Already enabled (ValidationException) or unsupported by the local emulator build.
        fwrite(STDOUT, 'TTL not changed: ' . $exception->getAwsErrorMessage() . "\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
