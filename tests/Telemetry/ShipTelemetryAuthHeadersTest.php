<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Command\ShipLogsCommand;
use Palliis\SharedHostingObservabilityBundle\Command\ShipTracesCommand;
use Palliis\SharedHostingObservabilityBundle\Telemetry\HttpHeaderParser;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpLogPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpProtobufLogPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpTracePayloadBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShipTelemetryAuthHeadersTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'motometeo_telemetry_'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    public function testShipLogsUsesBearerTokenWhenAuthorizationHeaderIsNotProvided(): void
    {
        $sourcePath = $this->tmpDir.DIRECTORY_SEPARATOR.'prod.log';
        file_put_contents($sourcePath, "{\"message\":\"hello\",\"level_name\":\"INFO\",\"channel\":\"app\",\"datetime\":\"2026-01-01T00:00:00+00:00\",\"context\":{},\"extra\":{}}\n");
        $statePath = $this->tmpDir.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'logs.offset.json';

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://logs.example.test/v1/logs', $url);
            self::assertSame('Bearer log-token', $this->headerValue($options['headers'] ?? [], 'Authorization'));
            self::assertSame('application/x-protobuf', $this->headerValue($options['headers'] ?? [], 'Content-Type'));
            self::assertStringContainsString('hello', (string) ($options['body'] ?? ''));
            self::assertStringContainsString('motometeo-api-shared-prod', (string) ($options['body'] ?? ''));

            return new MockResponse('', ['http_code' => 200]);
        });

        $command = new ShipLogsCommand(
            $httpClient,
            new OtlpLogPayloadBuilder(),
            new OtlpProtobufLogPayloadBuilder('motometeo-api-shared-prod', 'prod'),
            new HttpHeaderParser(),
            $this->tmpDir,
            $sourcePath,
            $statePath,
            'https://logs.example.test/v1/logs',
            '',
            'log-token',
            5.0,
            100,
            100000,
        );

        $result = (new CommandTester($command))->execute([]);

        self::assertSame(0, $result);
    }

    public function testShipLogsPrefersExplicitAuthorizationHeaderOverBearerToken(): void
    {
        $sourcePath = $this->tmpDir.DIRECTORY_SEPARATOR.'prod.log';
        file_put_contents($sourcePath, "{\"message\":\"hello\",\"level_name\":\"INFO\",\"channel\":\"app\",\"datetime\":\"2026-01-01T00:00:00+00:00\",\"context\":{},\"extra\":{}}\n");
        $statePath = $this->tmpDir.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'logs.offset.json';

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://logs.example.test/v1/logs', $url);
            self::assertSame('Bearer explicit-token', $this->headerValue($options['headers'] ?? [], 'Authorization'));

            return new MockResponse('', ['http_code' => 200]);
        });

        $command = new ShipLogsCommand(
            $httpClient,
            new OtlpLogPayloadBuilder(),
            new OtlpProtobufLogPayloadBuilder(),
            new HttpHeaderParser(),
            $this->tmpDir,
            $sourcePath,
            $statePath,
            'https://logs.example.test/v1/logs',
            'Authorization=Bearer explicit-token',
            'log-token-ignored',
            5.0,
            100,
            100000,
        );

        $result = (new CommandTester($command))->execute([]);

        self::assertSame(0, $result);
    }

    public function testShipLogsCanSendOtlpJsonWhenExplicitlyConfigured(): void
    {
        $sourcePath = $this->tmpDir.DIRECTORY_SEPARATOR.'prod.log';
        file_put_contents($sourcePath, "{\"message\":\"hello\",\"level_name\":\"INFO\",\"channel\":\"app\",\"datetime\":\"2026-01-01T00:00:00+00:00\",\"context\":{},\"extra\":{}}\n");
        $statePath = $this->tmpDir.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'logs.offset.json';

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://logs.example.test/v1/logs', $url);
            self::assertSame('application/json', $this->headerValue($options['headers'] ?? [], 'Content-Type'));
            self::assertStringContainsString('"resourceLogs"', (string) ($options['body'] ?? ''));

            return new MockResponse('', ['http_code' => 200]);
        });

        $command = new ShipLogsCommand(
            $httpClient,
            new OtlpLogPayloadBuilder(),
            new OtlpProtobufLogPayloadBuilder(),
            new HttpHeaderParser(),
            $this->tmpDir,
            $sourcePath,
            $statePath,
            'https://logs.example.test/v1/logs',
            '',
            '',
            5.0,
            100,
            100000,
            'otlp_json',
        );

        $result = (new CommandTester($command))->execute([]);

        self::assertSame(0, $result);
    }

    public function testShipLogsCanSendJsonLinesWhenExplicitlyConfigured(): void
    {
        $sourcePath = $this->tmpDir.DIRECTORY_SEPARATOR.'prod.log';
        file_put_contents($sourcePath, "{\"message\":\"hello\",\"level_name\":\"INFO\",\"channel\":\"app\",\"datetime\":\"2026-01-01T00:00:00+00:00\",\"context\":{},\"extra\":{}}\n");
        $statePath = $this->tmpDir.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'logs.offset.json';

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://logs.example.test/insert/jsonline', $url);
            self::assertSame('application/stream+json', $this->headerValue($options['headers'] ?? [], 'Content-Type'));
            self::assertStringContainsString('"message":"hello"', (string) ($options['body'] ?? ''));

            return new MockResponse('', ['http_code' => 200]);
        });

        $command = new ShipLogsCommand(
            $httpClient,
            new OtlpLogPayloadBuilder(),
            new OtlpProtobufLogPayloadBuilder(),
            new HttpHeaderParser(),
            $this->tmpDir,
            $sourcePath,
            $statePath,
            'https://logs.example.test/insert/jsonline',
            '',
            '',
            5.0,
            100,
            100000,
            'jsonl',
        );

        $result = (new CommandTester($command))->execute([]);

        self::assertSame(0, $result);
    }

    public function testShipTracesUsesBearerTokenWhenAuthorizationHeaderIsNotProvided(): void
    {
        $spoolDir = $this->tmpDir.DIRECTORY_SEPARATOR.'traces';
        mkdir($spoolDir, 0777, true);
        file_put_contents($spoolDir.DIRECTORY_SEPARATOR.'trace-1.jsonl', "{\"resource\":{\"service.name\":\"motometeo-api\",\"deployment.environment\":\"test\"},\"spans\":[{\"trace_id\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"span_id\":\"bbbbbbbbbbbbbbbb\",\"name\":\"synthetic.check\",\"kind\":\"SPAN_KIND_INTERNAL\",\"start_time_unix_nano\":100,\"end_time_unix_nano\":200,\"attributes\":{},\"status\":null}]}\n");

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://traces.example.test/v1/traces', $url);
            self::assertSame('Bearer trace-token', $this->headerValue($options['headers'] ?? [], 'Authorization'));

            return new MockResponse('', ['http_code' => 200]);
        });

        $command = new ShipTracesCommand(
            $httpClient,
            new OtlpTracePayloadBuilder(),
            new HttpHeaderParser(),
            $this->tmpDir,
            $spoolDir,
            'https://traces.example.test/v1/traces',
            '',
            'trace-token',
            5.0,
            0,
            10,
        );

        $result = (new CommandTester($command))->execute([]);

        self::assertSame(0, $result);
    }

    /**
     * @param array<string|int, string> $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (is_string($key) && 0 === strcasecmp($key, $name)) {
                return $value;
            }

            [$headerName, $headerValue] = array_pad(explode(':', $value, 2), 2, null);
            if (0 === strcasecmp(trim((string) $headerName), $name)) {
                return null === $headerValue ? '' : trim($headerValue);
            }
        }

        return null;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $item = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($item)) {
                $this->removeDir($item);
            } else {
                @unlink($item);
            }
        }

        @rmdir($path);
    }
}
