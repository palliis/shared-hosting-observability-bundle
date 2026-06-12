<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpLogPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class OtlpLogPayloadBuilderTest extends TestCase
{
    public function testBuildsConfiguredResourceAttributes(): void
    {
        $payload = (new OtlpLogPayloadBuilder('motometeo-api-shared-prod', 'prod'))->build([
            [
                'message' => 'hello',
                'level_name' => 'INFO',
                'channel' => 'app',
                'datetime' => '2026-01-01T00:00:00+00:00',
                'context' => [],
                'extra' => [],
            ],
        ]);

        $attributes = $payload['resourceLogs'][0]['resource']['attributes'];

        $this->assertSame('service.name', $attributes[0]['key']);
        $this->assertSame('motometeo-api-shared-prod', $attributes[0]['value']['stringValue']);
        $this->assertSame('deployment.environment', $attributes[1]['key']);
        $this->assertSame('prod', $attributes[1]['value']['stringValue']);
    }
}
