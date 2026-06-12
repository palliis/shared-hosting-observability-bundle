<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

final class OtlpLogPayloadBuilder
{
    use OtlpValueConverter;

    public function __construct(
        private string $serviceName = 'symfony-app',
        private string $environment = 'prod',
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    public function build(array $records): array
    {
        $logRecords = [];
        $resourceAttributes = [];

        foreach ($records as $record) {
            if ([] === $resourceAttributes) {
                $resourceAttributes = $this->buildResourceAttributes($record);
            }

            $logRecords[] = $this->convertRecord($record);
        }

        return [
            'resourceLogs' => [
                [
                    'resource' => ['attributes' => $resourceAttributes],
                    'scopeLogs' => [[
                        'scope' => ['name' => 'shared-hosting-observability.monolog'],
                        'logRecords' => $logRecords,
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function convertRecord(array $record): array
    {
        $timestamp = $this->toUnixNanos($record['datetime'] ?? null);
        $attributes = array_merge(
            $this->normalizeMap($record['context'] ?? []),
            $this->prefixKeys('log.', $this->normalizeMap($record['extra'] ?? [])),
            [
                'log.channel' => (string) ($record['channel'] ?? 'app'),
                'log.logger' => (string) ($record['channel'] ?? 'app'),
            ],
        );

        return [
            'timeUnixNano' => (string) $timestamp,
            'severityNumber' => $this->severityNumber((string) ($record['level_name'] ?? 'INFO')),
            'severityText' => strtoupper((string) ($record['level_name'] ?? 'INFO')),
            'body' => ['stringValue' => (string) ($record['message'] ?? '')],
            'attributes' => $this->convertAttributes($attributes),
            'traceId' => $this->extractHexString($record, ['context', 'trace_id'], 32),
            'spanId' => $this->extractHexString($record, ['context', 'span_id'], 16),
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<int, array{key: string, value: array<string, mixed>}>
     */
    private function buildResourceAttributes(array $record): array
    {
        $resource = [];
        $context = $this->normalizeMap($record['context'] ?? []);
        $extra = $this->normalizeMap($record['extra'] ?? []);
        $resource['service.name'] = $this->serviceName ?: (string) ($context['service.name'] ?? $extra['service.name'] ?? 'symfony-app');
        $resource['deployment.environment'] = $this->environment ?: (string) ($context['deployment.environment'] ?? $extra['deployment.environment'] ?? 'prod');

        return $this->convertAttributes($resource);
    }

    /** @return array<string, mixed> */
    private function normalizeMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function prefixKeys(string $prefix, array $values): array
    {
        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed[$prefix.$key] = $value;
        }

        return $prefixed;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string>         $path
     */
    private function extractHexString(array $record, array $path, int $length): string
    {
        $value = $record;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }
            $value = $value[$segment];
        }

        if (!is_string($value)) {
            return '';
        }

        $normalized = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{'.$length.'}$/', $normalized) ? $normalized : '';
    }

    private function toUnixNanos(mixed $datetime): int
    {
        if (is_string($datetime) && '' !== trim($datetime)) {
            try {
                $date = new \DateTimeImmutable($datetime);

                return ((int) $date->format('U')) * 1_000_000_000 + ((int) $date->format('u')) * 1000;
            } catch (\Throwable) {
            }
        }

        return (int) round(microtime(true) * 1_000_000_000);
    }

    private function severityNumber(string $levelName): int
    {
        return match (strtoupper($levelName)) {
            'DEBUG' => 5,
            'INFO', 'NOTICE' => 9,
            'WARNING' => 13,
            'ERROR' => 17,
            'CRITICAL' => 21,
            'ALERT' => 22,
            'EMERGENCY' => 24,
            default => 9,
        };
    }
}
