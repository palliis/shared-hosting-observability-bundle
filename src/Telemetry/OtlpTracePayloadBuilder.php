<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

final class OtlpTracePayloadBuilder
{
    use OtlpValueConverter;

    /**
     * @param array<int, array<string, mixed>> $traces
     *
     * @return array<string, mixed>
     */
    public function build(array $traces): array
    {
        $scopeSpans = [];
        $resourceAttributes = [];

        foreach ($traces as $trace) {
            if ([] === $resourceAttributes) {
                $resourceAttributes = $this->convertAttributes($trace['resource'] ?? []);
            }

            foreach (($trace['spans'] ?? []) as $span) {
                if (is_array($span)) {
                    $scopeSpans[] = $this->convertSpan($span);
                }
            }
        }

        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $resourceAttributes,
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'shared-hosting-observability.manual',
                            ],
                            'spans' => $scopeSpans,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $span
     *
     * @return array<string, mixed>
     */
    private function convertSpan(array $span): array
    {
        $payload = [
            'traceId' => (string) ($span['trace_id'] ?? ''),
            'spanId' => (string) ($span['span_id'] ?? ''),
            'name' => (string) ($span['name'] ?? 'span'),
            'kind' => (string) ($span['kind'] ?? 'SPAN_KIND_INTERNAL'),
            'startTimeUnixNano' => (string) ($span['start_time_unix_nano'] ?? '0'),
            'endTimeUnixNano' => (string) ($span['end_time_unix_nano'] ?? '0'),
            'attributes' => $this->convertAttributes($span['attributes'] ?? []),
        ];

        if (!empty($span['parent_span_id'])) {
            $payload['parentSpanId'] = (string) $span['parent_span_id'];
        }

        if (!empty($span['status']) && is_array($span['status'])) {
            $payload['status'] = $span['status'];
        }

        return $payload;
    }
}
