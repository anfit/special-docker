<?php

declare(strict_types=1);

namespace Special;

/**
 * Minimal Prometheus metrics for a PHP-per-request runtime.
 *
 * PHP processes share no memory, so samples are appended to a small file under an exclusive lock and summed
 * when /metrics is scraped (the file is compacted when it grows). Collection is OFF unless a metrics token
 * is configured (SPECIAL_METRICS_TOKEN): with no token there is no endpoint and no per-request I/O.
 *
 * The file lives in the container's temp directory, so counters restart from zero when the container is
 * recreated, which is normal for Prometheus counters (use rate()/increase()).
 */
final class Metrics
{
    /** Latency buckets in seconds. */
    private const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];
    private const COMPACT_ABOVE_BYTES = 1_048_576;

    /** @var array<string, array{0: string, 1: string}> family => [type, help] */
    private const FAMILIES = [
        'special_http_requests_total' => ['counter', 'HTTP requests handled, by method, route group and status code.'],
        'special_http_request_duration_seconds' => ['histogram', 'HTTP request duration in seconds, by route group.'],
        'special_mcp_requests_total' => ['counter', 'MCP JSON-RPC requests, by method and outcome.'],
        'special_login_attempts_total' => ['counter', 'Browser login attempts, by result (success, failure, blocked).'],
    ];

    private static ?string $path = null;

    public static function configure(?string $path): void
    {
        self::$path = $path;
    }

    public static function enabled(): bool
    {
        return self::$path !== null;
    }

    /** Collapse a request path to a low-cardinality route label; null = do not record. */
    public static function routeFor(string $path): ?string
    {
        if ($path === '/health' || $path === '/metrics') {
            return null;
        }

        return match (true) {
            $path === '/mcp' => 'mcp',
            $path === '/oauth/token', $path === '/oauth/register', $path === '/oauth/authorize' => $path,
            str_starts_with($path, '/.well-known/') => '/.well-known',
            in_array($path, ['/', '/login', '/logout', '/setup', '/account', '/account/password', '/projects', '/specs', '/specs/new'], true) => $path,
            str_starts_with($path, '/share/') => '/share/{token}',
            preg_match('#^/specs/\d+$#', $path) === 1 => '/specs/{id}',
            preg_match('#^/specs/\d+/(edit|delete)$#', $path, $m) === 1 => '/specs/{id}/' . $m[1],
            $path === '/admin/users', preg_match('#^/admin/users/\d+/[a-z-]+$#', $path) === 1 => '/admin/users',
            default => 'other',
        };
    }

    /** @param array<string, string> $labels */
    public static function inc(string $name, array $labels = [], float $by = 1.0): void
    {
        if (self::$path === null) {
            return;
        }
        self::append([self::line($name, $labels, $by)]);
    }

    /** @param array<string, string> $labels */
    public static function observe(string $family, array $labels, float $seconds): void
    {
        if (self::$path === null) {
            return;
        }
        $lines = [];
        foreach (self::BUCKETS as $bound) {
            if ($seconds <= $bound) {
                $lines[] = self::line($family . '_bucket', $labels + ['le' => self::number($bound)], 1.0);
            }
        }
        $lines[] = self::line($family . '_bucket', $labels + ['le' => '+Inf'], 1.0);
        $lines[] = self::line($family . '_sum', $labels, $seconds);
        $lines[] = self::line($family . '_count', $labels, 1.0);
        self::append($lines);
    }

    /**
     * Prometheus text exposition (format 0.0.4) of everything collected, plus the supplied gauges.
     *
     * @param list<array{name: string, help: string, labels: array<string, string>, value: float|int}> $gauges
     */
    public static function render(array $gauges = []): string
    {
        $samples = self::aggregate();
        $byFamily = [];
        foreach ($samples as [$name, $labels, $value]) {
            $family = self::familyOf($name);
            $byFamily[$family][] = [$name, $labels, $value];
        }
        ksort($byFamily);

        $out = '';
        foreach ($byFamily as $family => $rows) {
            [$type, $help] = self::FAMILIES[$family] ?? ['untyped', $family];
            $out .= '# HELP ' . $family . ' ' . $help . "\n# TYPE " . $family . ' ' . $type . "\n";
            usort($rows, static fn (array $a, array $b): int => [$a[0], self::sortKey($a[1])] <=> [$b[0], self::sortKey($b[1])]);
            foreach ($rows as [$name, $labels, $value]) {
                $out .= $name . self::labelString($labels) . ' ' . self::number($value) . "\n";
            }
        }
        foreach ($gauges as $gauge) {
            $out .= '# HELP ' . $gauge['name'] . ' ' . $gauge['help'] . "\n# TYPE " . $gauge['name'] . " gauge\n";
            $out .= $gauge['name'] . self::labelString($gauge['labels']) . ' ' . self::number($gauge['value']) . "\n";
        }

        return $out;
    }

    /** @param array<string, string> $labels */
    private static function line(string $name, array $labels, float $value): string
    {
        ksort($labels);
        return $name . "\t" . json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\t" . self::number($value) . "\n";
    }

    /** @param list<string> $lines */
    private static function append(array $lines): void
    {
        $handle = @fopen((string) self::$path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, implode('', $lines));
            fflush($handle);
            if (ftell($handle) > self::COMPACT_ABOVE_BYTES * 2) {
                self::compactLocked($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{0: string, 1: array<string, string>, 2: float}> */
    private static function aggregate(): array
    {
        if (self::$path === null) {
            return [];
        }
        $handle = @fopen(self::$path, 'c+');
        if ($handle === false) {
            return [];
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return [];
            }
            $samples = self::readLocked($handle);
            if (fstat($handle)['size'] > self::COMPACT_ABOVE_BYTES) {
                self::compactLocked($handle, $samples);
            }

            return $samples;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return list<array{0: string, 1: array<string, string>, 2: float}>
     */
    private static function readLocked($handle): array
    {
        rewind($handle);
        $sums = [];
        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"));
            if (count($parts) !== 3) {
                continue;
            }
            $labels = json_decode($parts[1], true);
            if (!is_array($labels)) {
                continue;
            }
            $key = $parts[0] . "\t" . $parts[1];
            $sums[$key] = ($sums[$key] ?? 0.0) + (float) $parts[2];
        }
        $samples = [];
        foreach ($sums as $key => $value) {
            [$name, $labelJson] = explode("\t", $key, 2);
            $samples[] = [$name, json_decode($labelJson, true), $value];
        }

        return $samples;
    }

    /**
     * @param resource $handle
     * @param list<array{0: string, 1: array<string, string>, 2: float}>|null $samples
     */
    private static function compactLocked($handle, ?array $samples = null): void
    {
        $samples ??= self::readLocked($handle);
        ftruncate($handle, 0);
        rewind($handle);
        foreach ($samples as [$name, $labels, $value]) {
            fwrite($handle, self::line($name, $labels, $value));
        }
        fflush($handle);
    }

    private static function familyOf(string $name): string
    {
        foreach (['_bucket', '_sum', '_count'] as $suffix) {
            if (str_ends_with($name, $suffix) && isset(self::FAMILIES[substr($name, 0, -strlen($suffix))])) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return $name;
    }

    /** @param array<string, string> $labels */
    private static function sortKey(array $labels): string
    {
        // Histogram buckets must come out in numeric `le` order, everything else alphabetically.
        $le = $labels['le'] ?? null;
        unset($labels['le']);
        ksort($labels);
        $rest = json_encode($labels, JSON_UNESCAPED_SLASHES);
        $order = $le === null ? '' : ($le === '+Inf' ? sprintf('%020.6f', 1e19) : sprintf('%020.6f', (float) $le));

        return $rest . '|' . $order;
    }

    /** @param array<string, string> $labels */
    private static function labelString(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = $key . '="' . strtr((string) $value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']) . '"';
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private static function number(float|int $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        return (string) (int) $value;
    }
}
