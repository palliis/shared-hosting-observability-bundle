<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

final class OtlpProtobufLogPayloadBuilder
{
    public function __construct(
        private string $serviceName = 'symfony-app',
        private string $environment = 'prod',
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function build(array $records): string
    {
        if ([] === $records) {
            return '';
        }

        return $this->message([
            $this->field(1, $this->resourceLogs($records)),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function resourceLogs(array $records): string
    {
        return $this->message([
            $this->field(1, $this->resource($records[0] ?? [])),
            $this->field(2, $this->scopeLogs($records)),
        ]);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resource(array $record): string
    {
        $context = $this->normalizeMap($record['context'] ?? []);
        $extra = $this->normalizeMap($record['extra'] ?? []);
        $attributes = [
            'service.name' => $this->serviceName ?: (string) ($context['service.name'] ?? $extra['service.name'] ?? 'symfony-app'),
            'deployment.environment' => $this->environment ?: (string) ($context['deployment.environment'] ?? $extra['deployment.environment'] ?? 'prod'),
        ];

        $fields = [];
        foreach ($attributes as $key => $value) {
            $fields[] = $this->field(1, $this->keyValue((string) $key, $value));
        }

        return $this->message($fields);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function scopeLogs(array $records): string
    {
        $fields = [
            $this->field(1, $this->instrumentationScope('shared-hosting-observability.monolog')),
        ];

        foreach ($records as $record) {
            $fields[] = $this->field(2, $this->logRecord($record));
        }

        return $this->message($fields);
    }

    private function instrumentationScope(string $name): string
    {
        return $this->message([
            $this->field(1, $name),
        ]);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function logRecord(array $record): string
    {
        $timestamp = $this->toUnixNanos($record['datetime'] ?? null);
        $levelName = strtoupper((string) ($record['level_name'] ?? 'INFO'));
        $attributes = array_merge(
            $this->normalizeMap($record['context'] ?? []),
            $this->prefixKeys('log.', $this->normalizeMap($record['extra'] ?? [])),
            [
                'log.channel' => (string) ($record['channel'] ?? 'app'),
                'log.logger' => (string) ($record['channel'] ?? 'app'),
            ],
        );

        $fields = [
            $this->fixed64Field(1, $timestamp),
            $this->varintField(2, $this->severityNumber($levelName)),
            $this->field(3, $levelName),
            $this->field(5, $this->anyValue((string) ($record['message'] ?? ''))),
            $this->fixed64Field(11, $timestamp),
        ];

        foreach ($attributes as $key => $value) {
            if (null !== $value) {
                $fields[] = $this->field(6, $this->keyValue((string) $key, $value));
            }
        }

        $traceId = $this->hexBytes($record, ['context', 'trace_id'], 32);
        if ('' !== $traceId) {
            $fields[] = $this->field(9, $traceId);
        }

        $spanId = $this->hexBytes($record, ['context', 'span_id'], 16);
        if ('' !== $spanId) {
            $fields[] = $this->field(10, $spanId);
        }

        return $this->message($fields);
    }

    private function keyValue(string $key, mixed $value): string
    {
        return $this->message([
            $this->field(1, $key),
            $this->field(2, $this->anyValue($value)),
        ]);
    }

    private function anyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $this->message([$this->varintField(2, $value ? 1 : 0)]);
        }

        if (is_int($value) && $value >= 0) {
            return $this->message([$this->varintField(3, $value)]);
        }

        if (is_float($value)) {
            return $this->message([$this->doubleField(4, $value)]);
        }

        if (is_array($value)) {
            if ($this->isList($value)) {
                $items = [];
                foreach ($value as $item) {
                    if (null !== $item) {
                        $items[] = $this->field(1, $this->anyValue($item));
                    }
                }

                return $this->message([$this->field(5, $this->message($items))]);
            }

            $items = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && null !== $item) {
                    $items[] = $this->field(1, $this->keyValue($key, $item));
                }
            }

            return $this->message([$this->field(6, $this->message($items))]);
        }

        return $this->message([$this->field(1, is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: ''))]);
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string>         $path
     */
    private function hexBytes(array $record, array $path, int $length): string
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
        if (!preg_match('/^[0-9a-f]{'.$length.'}$/', $normalized)) {
            return '';
        }

        return hex2bin($normalized) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $values
     *
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

    /**
     * @param list<string> $fields
     */
    private function message(array $fields): string
    {
        return implode('', array_filter($fields, static fn (string $field): bool => '' !== $field));
    }

    private function field(int $number, string $value): string
    {
        return $this->tag($number, 2).$this->varint(strlen($value)).$value;
    }

    private function varintField(int $number, int $value): string
    {
        return $this->tag($number, 0).$this->varint($value);
    }

    private function fixed64Field(int $number, int $value): string
    {
        return $this->tag($number, 1).pack('P', $value);
    }

    private function doubleField(int $number, float $value): string
    {
        return $this->tag($number, 1).pack('e', $value);
    }

    private function tag(int $number, int $wireType): string
    {
        return $this->varint(($number << 3) | $wireType);
    }

    private function varint(int $value): string
    {
        $bytes = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $bytes .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);

        return $bytes;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
