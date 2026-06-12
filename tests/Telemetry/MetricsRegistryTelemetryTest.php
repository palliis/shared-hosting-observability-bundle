<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class MetricsRegistryTelemetryTest extends TestCase
{
    public function testSyntheticMetricsAreRendered(): void
    {
        $registry = new MetricsRegistry(new ArrayAdapter(), null, 'motometeo');

        $registry->recordSyntheticCheck('health', 0.123, 200, true);
        $lines = implode("\n", $registry->renderPrometheusLines());

        $this->assertStringContainsString('motometeo_synthetic_up{check="health"} 1', $lines);
        $this->assertStringContainsString('motometeo_synthetic_duration_seconds{check="health"} 0.123', $lines);
        $this->assertStringContainsString('motometeo_synthetic_status_code{check="health"} 200', $lines);
    }
}
