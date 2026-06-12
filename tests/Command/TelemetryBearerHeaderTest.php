<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Command;

use Palliis\SharedHostingObservabilityBundle\Command\ShipLogsCommand;
use Palliis\SharedHostingObservabilityBundle\Command\ShipTracesCommand;
use Palliis\SharedHostingObservabilityBundle\Telemetry\HttpHeaderParser;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpLogPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpProtobufLogPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpTracePayloadBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelemetryBearerHeaderTest extends TestCase
{
    public function testShipLogsAddsBearerAuthorizationWhenDedicatedTokenIsProvided(): void
    {
        $command = new ShipLogsCommand(
            $this->createStub(HttpClientInterface::class),
            new OtlpLogPayloadBuilder(),
            new OtlpProtobufLogPayloadBuilder(),
            new HttpHeaderParser(),
            __DIR__,
            'var/log/prod.log',
            'var/telemetry/state/logs.offset.json',
            'https://logs.example.com/insert/opentelemetry/v1/logs',
            '',
            'logs-token',
            5.0,
            100,
            100000,
        );

        $headers = $this->invokeBuildHeaders($command);

        $this->assertSame('Bearer logs-token', $headers['Authorization']);
    }

    public function testShipTracesDoesNotOverrideExplicitAuthorizationHeader(): void
    {
        $command = new ShipTracesCommand(
            $this->createStub(HttpClientInterface::class),
            new OtlpTracePayloadBuilder(),
            new HttpHeaderParser(),
            __DIR__,
            'var/telemetry/traces',
            'https://traces.example.com/insert/opentelemetry/v1/traces',
            'Authorization=Bearer from-headers',
            'trace-token',
            5.0,
            0,
            10,
        );

        $headers = $this->invokeBuildHeaders($command);

        $this->assertSame('Bearer from-headers', $headers['Authorization']);
    }

    /** @return array<string, string> */
    private function invokeBuildHeaders(object $command): array
    {
        $method = new \ReflectionMethod($command, 'buildHeaders');

        /** @var array<string, string> $headers */
        $headers = $method->invoke($command);

        return $headers;
    }
}
