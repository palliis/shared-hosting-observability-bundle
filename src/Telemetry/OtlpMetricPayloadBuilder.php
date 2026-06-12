<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

final class OtlpMetricPayloadBuilder
{
    use OtlpValueConverter;

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public function build(array $snapshot, string $serviceName, string $environment, string $metricPrefix = 'sho'): array
    {
        $metricPrefix = $this->normalizeMetricPrefix($metricPrefix);
        $timeUnixNano = (string) ((int) round(microtime(true) * 1_000_000_000));
        $metrics = [];

        foreach ($snapshot['provider_counters'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $metrics[] = [
                'name' => $this->metricName($metricPrefix, 'provider.requests'),
                'description' => 'Total upstream provider requests',
                'sum' => [
                    'aggregationTemporality' => 2,
                    'isMonotonic' => true,
                    'dataPoints' => [[
                        'attributes' => $this->convertAttributes($entry['labels'] ?? []),
                        'asInt' => (string) ($entry['count'] ?? 0),
                        'timeUnixNano' => $timeUnixNano,
                    ]],
                ],
            ];
        }

        foreach ($snapshot['provider_histograms'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $bounds = [];
            $bucketCounts = [];
            foreach (($entry['buckets'] ?? []) as $bound => $count) {
                $bounds[] = (float) $bound;
                $bucketCounts[] = (int) $count;
            }
            $bucketCounts[] = (int) ($entry['count'] ?? 0);

            $metrics[] = [
                'name' => $this->metricName($metricPrefix, 'provider.request.duration'),
                'unit' => 's',
                'description' => 'Upstream provider request duration in seconds',
                'histogram' => [
                    'aggregationTemporality' => 2,
                    'dataPoints' => [[
                        'attributes' => $this->convertAttributes($entry['labels'] ?? []),
                        'count' => (string) ($entry['count'] ?? 0),
                        'sum' => (float) ($entry['sum'] ?? 0.0),
                        'bucketCounts' => array_map(static fn (int $value): string => (string) $value, $bucketCounts),
                        'explicitBounds' => $bounds,
                        'timeUnixNano' => $timeUnixNano,
                    ]],
                ],
            ];
        }

        foreach ($snapshot['synthetic_checks'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $labels = $entry['labels'] ?? [];
            $metrics[] = $this->gaugeMetric($this->metricName($metricPrefix, 'synthetic.up'), 'Last synthetic check success state', $labels, (int) ($entry['up'] ?? 0), $timeUnixNano);
            $metrics[] = $this->gaugeMetric($this->metricName($metricPrefix, 'synthetic.duration'), 'Last synthetic check duration in seconds', $labels, (float) ($entry['duration'] ?? 0.0), $timeUnixNano, 's');
            $metrics[] = $this->gaugeMetric($this->metricName($metricPrefix, 'synthetic.status_code'), 'Last synthetic check HTTP status code', $labels, (int) ($entry['status_code'] ?? 0), $timeUnixNano);
            $metrics[] = $this->gaugeMetric($this->metricName($metricPrefix, 'synthetic.last_checked'), 'Last synthetic check timestamp', $labels, (int) ($entry['checked_at'] ?? 0), $timeUnixNano, 's');
            $metrics[] = $this->sumMetric($this->metricName($metricPrefix, 'synthetic.checks'), 'Total synthetic checks', $labels, (int) ($entry['total'] ?? 0), $timeUnixNano);
            $metrics[] = $this->sumMetric($this->metricName($metricPrefix, 'synthetic.failures'), 'Total failed synthetic checks', $labels, (int) ($entry['failures'] ?? 0), $timeUnixNano);
        }

        return [
            'resourceMetrics' => [[
                'resource' => ['attributes' => $this->convertAttributes([
                    'service.name' => $serviceName,
                    'deployment.environment' => $environment,
                ])],
                'scopeMetrics' => [[
                    'scope' => ['name' => 'shared-hosting-observability.metrics'],
                    'metrics' => $metrics,
                ]],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $labels
     *
     * @return array<string, mixed>
     */
    private function gaugeMetric(string $name, string $description, array $labels, int|float $value, string $timeUnixNano, string $unit = ''): array
    {
        $point = [
            'attributes' => $this->convertAttributes($labels),
            'timeUnixNano' => $timeUnixNano,
        ];
        if (is_int($value)) {
            $point['asInt'] = (string) $value;
        } else {
            $point['asDouble'] = $value;
        }

        $metric = [
            'name' => $name,
            'description' => $description,
            'gauge' => ['dataPoints' => [$point]],
        ];

        if ('' !== $unit) {
            $metric['unit'] = $unit;
        }

        return $metric;
    }

    /**
     * @param array<string, mixed> $labels
     *
     * @return array<string, mixed>
     */
    private function sumMetric(string $name, string $description, array $labels, int $value, string $timeUnixNano): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'sum' => [
                'aggregationTemporality' => 2,
                'isMonotonic' => true,
                'dataPoints' => [[
                    'attributes' => $this->convertAttributes($labels),
                    'asInt' => (string) $value,
                    'timeUnixNano' => $timeUnixNano,
                ]],
            ],
        ];
    }

    private function metricName(string $prefix, string $suffix): string
    {
        return $prefix.'.'.$suffix;
    }

    private function normalizeMetricPrefix(string $prefix): string
    {
        $prefix = strtolower(trim($prefix));
        $prefix = preg_replace('/[^a-z0-9_.]/', '.', $prefix) ?: 'sho';
        $prefix = trim($prefix, '.');

        return '' === $prefix ? 'sho' : $prefix;
    }
}
