<?php

namespace Palliis\SharedHostingObservabilityBundle\Metrics;

use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceRecorder;
use Psr\Cache\CacheItemPoolInterface;

final class MetricsRegistry
{
    private const CACHE_KEY = 'shared_hosting_observability.metrics.registry';

    /**
     * Buckets in seconds.
     *
     * @var float[]
     */
    private array $providerHistogramBuckets = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    /**
     * @var array<string, array{labels: array<string, string>, count: int}>
     */
    private array $providerCounters = [];

    /**
     * @var array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: float, count: int}>
     */
    private array $providerHistograms = [];

    /**
     * @var array<string, array{labels: array<string, string>, up: int, duration: float, status_code: int, checked_at: int, total: int, failures: int}>
     */
    private array $syntheticChecks = [];

    private bool $loaded = false;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ?TraceRecorder $traceRecorder = null,
        private string $metricPrefix = 'sho',
        private string $projectDir = '',
        private string $lockPath = '',
    ) {
        $this->metricPrefix = $this->normalizeMetricPrefix($this->metricPrefix);
        $this->lockPath = '' !== trim($this->lockPath)
            ? $this->lockPath
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.'shared-hosting-observability-metrics-'.sha1($this->projectDir ?: __DIR__).'.lock';
    }

    public function recordProviderRequest(
        string $serviceType,
        string $provider,
        float $durationSeconds,
        int $statusCode,
        bool $success,
    ): void {
        $durationSeconds = max(0.0, $durationSeconds);

        try {
            $this->mutateRegistry(function () use ($serviceType, $provider, $durationSeconds, $statusCode, $success): void {
                $counterLabels = [
                    'service_type' => $serviceType,
                    'provider' => $provider,
                    'success' => $success ? 'true' : 'false',
                    'status_code' => (string) $statusCode,
                ];
                $counterKey = $this->buildKey($counterLabels);

                if (!isset($this->providerCounters[$counterKey])) {
                    $this->providerCounters[$counterKey] = [
                        'labels' => $counterLabels,
                        'count' => 0,
                    ];
                }
                ++$this->providerCounters[$counterKey]['count'];

                $histogramLabels = [
                    'service_type' => $serviceType,
                    'provider' => $provider,
                ];
                $histogramKey = $this->buildKey($histogramLabels);

                if (!isset($this->providerHistograms[$histogramKey])) {
                    $bucketCounts = [];
                    foreach ($this->providerHistogramBuckets as $bucket) {
                        $bucketCounts[$this->formatBucket($bucket)] = 0;
                    }

                    $this->providerHistograms[$histogramKey] = [
                        'labels' => $histogramLabels,
                        'buckets' => $bucketCounts,
                        'sum' => 0.0,
                        'count' => 0,
                    ];
                }

                $histogram = &$this->providerHistograms[$histogramKey];
                $histogram['sum'] += $durationSeconds;
                ++$histogram['count'];

                foreach ($this->providerHistogramBuckets as $bucket) {
                    if ($durationSeconds <= $bucket) {
                        $bucketKey = $this->formatBucket($bucket);
                        ++$histogram['buckets'][$bucketKey];
                    }
                }
                unset($histogram);
            });
        } catch (\Throwable) {
            // Observability must never break provider calls.
        }

        try {
            $this->traceRecorder?->recordProviderRequest($serviceType, $provider, $durationSeconds, $statusCode, $success);
        } catch (\Throwable) {
            // Observability must never break provider calls.
        }
    }

    public function recordSyntheticCheck(string $name, float $durationSeconds, int $statusCode, bool $success): void
    {
        $durationSeconds = max(0.0, $durationSeconds);
        $checkedAt = time();

        try {
            $this->mutateRegistry(function () use ($name, $durationSeconds, $statusCode, $success, $checkedAt): void {
                $labels = ['check' => $name];
                $key = $this->buildKey($labels);

                if (!isset($this->syntheticChecks[$key])) {
                    $this->syntheticChecks[$key] = [
                        'labels' => $labels,
                        'up' => 0,
                        'duration' => 0.0,
                        'status_code' => 0,
                        'checked_at' => 0,
                        'total' => 0,
                        'failures' => 0,
                    ];
                }

                $this->syntheticChecks[$key]['up'] = $success ? 1 : 0;
                $this->syntheticChecks[$key]['duration'] = $durationSeconds;
                $this->syntheticChecks[$key]['status_code'] = $statusCode;
                $this->syntheticChecks[$key]['checked_at'] = $checkedAt;
                ++$this->syntheticChecks[$key]['total'];
                if (!$success) {
                    ++$this->syntheticChecks[$key]['failures'];
                }
            });
        } catch (\Throwable) {
            // Observability must never break synthetic checks.
        }
    }

    /**
     * @return string[]
     */
    public function renderPrometheusLines(): array
    {
        $this->loadFromCache();
        $lines = [];

        $lines[] = sprintf('# HELP %s Total upstream provider requests', $this->metricName('provider_requests_total'));
        $lines[] = sprintf('# TYPE %s counter', $this->metricName('provider_requests_total'));
        foreach ($this->providerCounters as $entry) {
            $lines[] = sprintf(
                '%s%s %d',
                $this->metricName('provider_requests_total'),
                $this->formatLabels($entry['labels']),
                $entry['count']
            );
        }

        $lines[] = sprintf('# HELP %s Upstream provider request duration in seconds', $this->metricName('provider_request_duration_seconds'));
        $lines[] = sprintf('# TYPE %s histogram', $this->metricName('provider_request_duration_seconds'));
        foreach ($this->providerHistograms as $entry) {
            foreach ($entry['buckets'] as $bucketValue => $bucketCount) {
                $bucketLabels = $entry['labels'];
                $bucketLabels['le'] = $bucketValue;
                $lines[] = sprintf(
                    '%s_bucket%s %d',
                    $this->metricName('provider_request_duration_seconds'),
                    $this->formatLabels($bucketLabels),
                    $bucketCount
                );
            }

            $infLabels = $entry['labels'];
            $infLabels['le'] = '+Inf';
            $lines[] = sprintf(
                '%s_bucket%s %d',
                $this->metricName('provider_request_duration_seconds'),
                $this->formatLabels($infLabels),
                $entry['count']
            );

            $lines[] = sprintf(
                '%s_sum%s %s',
                $this->metricName('provider_request_duration_seconds'),
                $this->formatLabels($entry['labels']),
                $this->formatFloat($entry['sum'])
            );
            $lines[] = sprintf(
                '%s_count%s %d',
                $this->metricName('provider_request_duration_seconds'),
                $this->formatLabels($entry['labels']),
                $entry['count']
            );
        }

        $lines[] = sprintf('# HELP %s Last synthetic check success state', $this->metricName('synthetic_up'));
        $lines[] = sprintf('# TYPE %s gauge', $this->metricName('synthetic_up'));
        foreach ($this->syntheticChecks as $entry) {
            $lines[] = sprintf(
                '%s%s %d',
                $this->metricName('synthetic_up'),
                $this->formatLabels($entry['labels']),
                $entry['up']
            );
        }

        $lines[] = sprintf('# HELP %s Last synthetic check duration in seconds', $this->metricName('synthetic_duration_seconds'));
        $lines[] = sprintf('# TYPE %s gauge', $this->metricName('synthetic_duration_seconds'));
        foreach ($this->syntheticChecks as $entry) {
            $lines[] = sprintf(
                '%s%s %s',
                $this->metricName('synthetic_duration_seconds'),
                $this->formatLabels($entry['labels']),
                $this->formatFloat($entry['duration'])
            );
        }

        $lines[] = sprintf('# HELP %s Last synthetic check HTTP status code', $this->metricName('synthetic_status_code'));
        $lines[] = sprintf('# TYPE %s gauge', $this->metricName('synthetic_status_code'));
        foreach ($this->syntheticChecks as $entry) {
            $lines[] = sprintf(
                '%s%s %d',
                $this->metricName('synthetic_status_code'),
                $this->formatLabels($entry['labels']),
                $entry['status_code']
            );
        }

        $lines[] = sprintf('# HELP %s Last synthetic check timestamp', $this->metricName('synthetic_last_checked_timestamp_seconds'));
        $lines[] = sprintf('# TYPE %s gauge', $this->metricName('synthetic_last_checked_timestamp_seconds'));
        foreach ($this->syntheticChecks as $entry) {
            $lines[] = sprintf(
                '%s%s %d',
                $this->metricName('synthetic_last_checked_timestamp_seconds'),
                $this->formatLabels($entry['labels']),
                $entry['checked_at']
            );
        }

        $lines[] = sprintf('# HELP %s Total synthetic checks', $this->metricName('synthetic_checks_total'));
        $lines[] = sprintf('# TYPE %s counter', $this->metricName('synthetic_checks_total'));
        foreach ($this->syntheticChecks as $entry) {
            $lines[] = sprintf(
                '%s%s %d',
                $this->metricName('synthetic_checks_total'),
                $this->formatLabels($entry['labels']),
                $entry['total']
            );
        }

        $lines[] = sprintf('# HELP %s Total failed synthetic checks', $this->metricName('synthetic_failures_total'));
        $lines[] = sprintf('# TYPE %s counter', $this->metricName('synthetic_failures_total'));
        foreach ($this->syntheticChecks as $entry) {
            $lines[] = sprintf(
                '%s%s %d',
                $this->metricName('synthetic_failures_total'),
                $this->formatLabels($entry['labels']),
                $entry['failures']
            );
        }

        return $lines;
    }

    /**
     * @return array{
     *     provider_counters: array<string, array{labels: array<string, string>, count: int}>,
     *     provider_histograms: array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: float, count: int}>,
     *     synthetic_checks: array<string, array{labels: array<string, string>, up: int, duration: float, status_code: int, checked_at: int, total: int, failures: int}>
     * }
     */
    public function snapshot(): array
    {
        $this->loadFromCache();

        return [
            'provider_counters' => $this->providerCounters,
            'provider_histograms' => $this->providerHistograms,
            'synthetic_checks' => $this->syntheticChecks,
        ];
    }

    private function loadFromCache(): void
    {
        if ($this->loaded) {
            return;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $data = $item->get();
            if (is_array($data)) {
                $counters = $data['counters'] ?? null;
                $histograms = $data['histograms'] ?? null;
                if (is_array($counters)) {
                    $this->providerCounters = $counters;
                }
                if (is_array($histograms)) {
                    $this->providerHistograms = $histograms;
                }
                $syntheticChecks = $data['synthetic_checks'] ?? null;
                if (is_array($syntheticChecks)) {
                    $this->syntheticChecks = $syntheticChecks;
                }
            }
        }

        $this->loaded = true;
    }

    /**
     * @param \Closure(): void $mutation
     */
    private function mutateRegistry(\Closure $mutation): void
    {
        $lock = $this->openLock();

        try {
            $this->loaded = false;
            $this->loadFromCache();
            $mutation();
            $this->persist();
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function persist(): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set([
            'counters' => $this->providerCounters,
            'histograms' => $this->providerHistograms,
            'synthetic_checks' => $this->syntheticChecks,
        ]);
        $this->cache->save($item);
    }

    /**
     * @return resource|null
     */
    private function openLock()
    {
        $lockPath = $this->resolvePath($this->lockPath);
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            return null;
        }

        $lock = @fopen($lockPath, 'c');
        if (!$lock) {
            return null;
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
            return $path;
        }

        if ('' !== $this->projectDir) {
            return $this->projectDir.DIRECTORY_SEPARATOR.$path;
        }

        return $path;
    }

    /**
     * @param array<string, string> $labels
     */
    private function buildKey(array $labels): string
    {
        ksort($labels);
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode('|', $parts);
    }

    /**
     * @param array<string, string> $labels
     */
    private function formatLabels(array $labels): string
    {
        if ([] === $labels) {
            return '';
        }

        ksort($labels);
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key.'="'.$this->escapeLabelValue($value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value);
    }

    private function formatBucket(float $bucket): string
    {
        if ($bucket === (float) (int) $bucket) {
            return (string) (int) $bucket;
        }

        return rtrim(rtrim(sprintf('%.6F', $bucket), '0'), '.');
    }

    private function formatFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }

    private function metricName(string $suffix): string
    {
        return $this->metricPrefix.'_'.$suffix;
    }

    private function normalizeMetricPrefix(string $prefix): string
    {
        $prefix = strtolower(trim($prefix));
        $prefix = preg_replace('/[^a-z0-9_]/', '_', $prefix) ?: 'sho';
        $prefix = trim($prefix, '_');

        return '' === $prefix ? 'sho' : $prefix;
    }
}
