<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpProtobufLogPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type Field array{wire: int, value: int|string}
 * @phpstan-type FieldMap array<int, list<Field>>
 */
final class OtlpProtobufLogPayloadBuilderTest extends TestCase
{
    public function testBuildsDecodableOtlpProtobufLogPayload(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);

        $payload = (new OtlpProtobufLogPayloadBuilder('test-app', 'test'))->build([
            [
                'message' => 'provider failed',
                'level_name' => 'ERROR',
                'channel' => 'app',
                'datetime' => '2026-01-01T00:00:00+00:00',
                'context' => [
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'attempt' => 3,
                ],
                'extra' => [
                    'tenant' => 'demo',
                ],
            ],
        ]);

        $root = $this->readFields($payload);
        $resourceLogs = $this->messageField($root, 1);
        $resource = $this->messageField($resourceLogs, 1);
        $scopeLogs = $this->messageField($resourceLogs, 2);
        $scope = $this->messageField($scopeLogs, 1);
        $logRecord = $this->messageField($scopeLogs, 2);
        $body = $this->messageField($logRecord, 5);

        self::assertSame('service.name', $this->stringField($this->messageField($resource, 1), 1));
        self::assertSame('test-app', $this->stringField($this->messageField($this->messageField($resource, 1), 2), 1));
        self::assertSame('shared-hosting-observability.monolog', $this->stringField($scope, 1));
        self::assertSame(17, $this->intField($logRecord, 2));
        self::assertSame('ERROR', $this->stringField($logRecord, 3));
        self::assertSame('provider failed', $this->stringField($body, 1));
        self::assertSame(hex2bin($traceId), $this->bytesField($logRecord, 9));
        self::assertSame(hex2bin($spanId), $this->bytesField($logRecord, 10));
    }

    /**
     * @phpstan-return FieldMap
     */
    private function readFields(string $message): array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($message);

        while ($offset < $length) {
            $tag = $this->readVarint($message, $offset);
            $number = $tag >> 3;
            $wireType = $tag & 0x07;

            if (0 === $wireType) {
                $value = $this->readVarint($message, $offset);
            } elseif (1 === $wireType) {
                $value = substr($message, $offset, 8);
                $offset += 8;
            } elseif (2 === $wireType) {
                $fieldLength = $this->readVarint($message, $offset);
                $value = substr($message, $offset, $fieldLength);
                $offset += $fieldLength;
            } else {
                self::fail(sprintf('Unsupported protobuf wire type %d.', $wireType));
            }

            $fields[$number][] = [
                'wire' => $wireType,
                'value' => $value,
            ];
        }

        return $fields;
    }

    /**
     * @phpstan-param FieldMap $fields
     *
     * @phpstan-return FieldMap
     */
    private function messageField(array $fields, int $number, int $index = 0): array
    {
        $value = $this->field($fields, $number, $index)['value'];
        self::assertIsString($value);

        return $this->readFields($value);
    }

    /**
     * @phpstan-param FieldMap $fields
     */
    private function stringField(array $fields, int $number, int $index = 0): string
    {
        $value = $this->field($fields, $number, $index)['value'];
        self::assertIsString($value);

        return $value;
    }

    /**
     * @phpstan-param FieldMap $fields
     */
    private function bytesField(array $fields, int $number, int $index = 0): string
    {
        return $this->stringField($fields, $number, $index);
    }

    /**
     * @phpstan-param FieldMap $fields
     */
    private function intField(array $fields, int $number, int $index = 0): int
    {
        $value = $this->field($fields, $number, $index)['value'];
        self::assertIsInt($value);

        return $value;
    }

    /**
     * @phpstan-param FieldMap $fields
     *
     * @phpstan-return Field
     */
    private function field(array $fields, int $number, int $index): array
    {
        self::assertArrayHasKey($number, $fields);
        self::assertArrayHasKey($index, $fields[$number]);

        return $fields[$number][$index];
    }

    private function readVarint(string $message, int &$offset): int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($message);

        do {
            if ($offset >= $length) {
                self::fail('Unexpected end of protobuf varint.');
            }

            $byte = ord($message[$offset]);
            ++$offset;
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $result;
    }
}
