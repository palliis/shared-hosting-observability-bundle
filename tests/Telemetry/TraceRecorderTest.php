<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceRecorder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceSpoolWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TraceRecorderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'motometeo-traces-'.bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function testCompleteRequestSpoolsSampledTrace(): void
    {
        $recorder = new TraceRecorder(
            true,
            'motometeo-api',
            'motometeo',
            'test',
            '',
            1.0,
            1.0,
            2500,
            10,
            new TraceSpoolWriter(dirname(__DIR__, 2), $this->tempDir, 1_000_000),
        );

        $request = Request::create('/api/v1/info', 'GET');
        $request->attributes->set('_route', 'app_info');

        $recorder->startRequest($request);
        $recorder->recordProviderRequest('weather', 'openmeteo', 0.125, 200, true);
        $recorder->completeRequest($request, new Response('', 200));

        $files = glob($this->tempDir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        $this->assertCount(1, $files);

        $trace = json_decode((string) file_get_contents($files[0]), true);
        $this->assertIsArray($trace);
        $this->assertSame('motometeo-api', $trace['resource']['service.name']);
        $this->assertSame('test', $trace['resource']['deployment.environment']);
        $this->assertCount(2, $trace['spans']);
        $this->assertContains('SPAN_KIND_SERVER', array_column($trace['spans'], 'kind'));
        $this->assertContains('provider.weather.openmeteo', array_column($trace['spans'], 'name'));
    }

    public function testProviderFailureUsesErrorSampling(): void
    {
        $recorder = new TraceRecorder(
            true,
            'motometeo-api',
            'motometeo',
            'test',
            '',
            0.0,
            1.0,
            0,
            10,
            new TraceSpoolWriter(dirname(__DIR__, 2), $this->tempDir, 1_000_000),
        );

        $request = Request::create('/api/v1/info', 'GET');
        $recorder->startRequest($request);
        $recorder->recordProviderRequest('weather', 'openmeteo', 0.1, 500, false);
        $recorder->completeRequest($request, new Response('', 200));

        $files = glob($this->tempDir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        $this->assertCount(1, $files);
    }
}
