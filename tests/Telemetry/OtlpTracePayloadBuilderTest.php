<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpTracePayloadBuilder;
use PHPUnit\Framework\TestCase;

class OtlpTracePayloadBuilderTest extends TestCase
{
    public function testBuildsOtlpTracePayload(): void
    {
        $payload = (new OtlpTracePayloadBuilder())->build([
            [
                'resource' => [
                    'service.name' => 'motometeo-api',
                    'deployment.environment' => 'test',
                ],
                'spans' => [
                    [
                        'trace_id' => str_repeat('a', 32),
                        'span_id' => str_repeat('b', 16),
                        'name' => 'app_info',
                        'kind' => 'SPAN_KIND_SERVER',
                        'start_time_unix_nano' => 100,
                        'end_time_unix_nano' => 200,
                        'attributes' => [
                            'http.response.status_code' => 200,
                            'error' => false,
                        ],
                        'status' => null,
                    ],
                ],
            ],
        ]);

        $span = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $this->assertSame(str_repeat('a', 32), $span['traceId']);
        $this->assertSame(str_repeat('b', 16), $span['spanId']);
        $this->assertSame('100', $span['startTimeUnixNano']);
        $this->assertSame('SPAN_KIND_SERVER', $span['kind']);
        $this->assertSame('motometeo-api', $payload['resourceSpans'][0]['resource']['attributes'][0]['value']['stringValue']);
    }
}
