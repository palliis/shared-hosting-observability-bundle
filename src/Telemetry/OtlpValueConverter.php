<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

trait OtlpValueConverter
{
    /**
     * @return array<int, array{key: string, value: array<string, mixed>}>
     */
    private function convertAttributes(mixed $attributes): array
    {
        if (!is_array($attributes)) {
            return [];
        }

        $converted = [];
        foreach ($attributes as $key => $value) {
            $attributeValue = $this->convertAttributeValue($value);
            if (null === $attributeValue) {
                continue;
            }

            $converted[] = [
                'key' => (string) $key,
                'value' => $attributeValue,
            ];
        }

        return $converted;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertAttributeValue(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }

        if (is_bool($value)) {
            return ['boolValue' => $value];
        }

        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (is_string($value)) {
            return ['stringValue' => $value];
        }

        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                $converted = $this->convertAttributeValue($item);
                if (null !== $converted) {
                    $values[] = $converted;
                }
            }

            return ['arrayValue' => ['values' => $values]];
        }

        return ['stringValue' => json_encode($value, JSON_UNESCAPED_SLASHES) ?: ''];
    }
}
