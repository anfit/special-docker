<?php

declare(strict_types=1);

/**
 * Post-deploy sanity check: configuration is valid, the table is reachable and shaped as expected,
 * and (with --expect-admin) a first administrator exists.
 *
 *   docker compose exec app php scripts/deployment-check.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Special\Storage\DynamoSchema;

$failures = [];
$report = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    fwrite(STDOUT, sprintf("[%s] %s%s\n", $ok ? ' ok ' : 'FAIL', $label, $detail !== '' ? ' - ' . $detail : ''));
    if (!$ok) {
        $failures[] = $label;
    }
};

try {
    $config = special_config();
    $report('configuration', true, 'env=' . $config['environment'] . ' store=' . $config['store']);
    $store = special_store($config);
    $store->ping();
    $report('store reachable', true);

    if ($config['store'] === 'dynamodb') {
        $client = $store->client();
        $table = $client->describeTable(['TableName' => $config['dynamodb_table']])['Table'];
        $report('table active', ($table['TableStatus'] ?? '') === 'ACTIVE', (string) ($table['TableStatus'] ?? ''));
        $indexes = array_map(static fn (array $index): string => (string) $index['IndexName'], $table['GlobalSecondaryIndexes'] ?? []);
        sort($indexes);
        $report('indexes gsi1, gsi2', $indexes === ['gsi1', 'gsi2'], implode(',', $indexes));
        $ttl = $client->describeTimeToLive(['TableName' => $config['dynamodb_table']])['TimeToLiveDescription'] ?? [];
        $report(
            'TTL enabled',
            ($ttl['TimeToLiveStatus'] ?? '') === 'ENABLED' && ($ttl['AttributeName'] ?? '') === DynamoSchema::TTL_ATTRIBUTE,
            (string) ($ttl['TimeToLiveStatus'] ?? 'unknown'),
        );
    }

    $report('administrator exists', $store->listUsers() !== [], 'run scripts/bootstrap-admin.php if this fails');
} catch (Throwable $exception) {
    $report('check aborted', false, get_class($exception) . ': ' . $exception->getMessage());
}

exit($failures === [] ? 0 : 1);
