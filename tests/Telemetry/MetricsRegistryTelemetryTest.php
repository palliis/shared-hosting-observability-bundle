<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class MetricsRegistryTelemetryTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        $this->lockPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sho_metrics_'.bin2hex(random_bytes(6)).'.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
    }

    public function testSyntheticMetricsAreRendered(): void
    {
        $registry = new MetricsRegistry(new ArrayAdapter(), null, 'motometeo');

        $registry->recordSyntheticCheck('health', 0.123, 200, true);
        $lines = implode("\n", $registry->renderPrometheusLines());

        $this->assertStringContainsString('motometeo_synthetic_up{check="health"} 1', $lines);
        $this->assertStringContainsString('motometeo_synthetic_duration_seconds{check="health"} 0.123', $lines);
        $this->assertStringContainsString('motometeo_synthetic_status_code{check="health"} 200', $lines);
    }

    public function testRegistryReloadsUnderLockBeforePersistingMutation(): void
    {
        $cache = new ArrayAdapter();
        $first = new MetricsRegistry($cache, null, 'sho', '', $this->lockPath);
        $second = new MetricsRegistry($cache, null, 'sho', '', $this->lockPath);

        $first->recordProviderRequest('weather', 'openmeteo', 0.1, 200, true);
        $second->recordProviderRequest('weather', 'openmeteo', 0.2, 200, true);
        $first->recordProviderRequest('weather', 'openmeteo', 0.3, 200, true);

        $snapshot = (new MetricsRegistry($cache, null, 'sho', '', $this->lockPath))->snapshot();
        $counters = array_values($snapshot['provider_counters']);
        $histograms = array_values($snapshot['provider_histograms']);

        $this->assertCount(1, $counters);
        $this->assertSame(3, $counters[0]['count']);
        $this->assertCount(1, $histograms);
        $this->assertSame(3, $histograms[0]['count']);
    }
}
