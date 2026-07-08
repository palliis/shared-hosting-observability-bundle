<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\DependencyInjection;

use Palliis\SharedHostingObservabilityBundle\Command\ShipLogsCommand;
use Palliis\SharedHostingObservabilityBundle\Command\ShipMetricsCommand;
use Palliis\SharedHostingObservabilityBundle\Command\ShipTracesCommand;
use Palliis\SharedHostingObservabilityBundle\Command\SyntheticCheckCommand;
use Palliis\SharedHostingObservabilityBundle\Command\TelemetryGcCommand;
use Palliis\SharedHostingObservabilityBundle\DependencyInjection\SharedHostingObservabilityExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class SharedHostingObservabilityExtensionTest extends TestCase
{
    public function testExtensionRegistersServicesAndCompilesContainer(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.logs_dir', sys_get_temp_dir());
        $container->setDefinition('http_client', (new Definition())->setSynthetic(true)->setPublic(true));
        $container->setDefinition(LoggerInterface::class, (new Definition(NullLogger::class))->setPublic(true));
        $container->setDefinition('cache.app', (new Definition(ArrayAdapter::class))->setPublic(true));

        (new SharedHostingObservabilityExtension())->load([
            [
                'service_name' => 'test-app',
                'environment' => 'test',
                'logs' => [
                    'request_logging_enabled' => true,
                ],
                'traces' => [
                    'enabled' => true,
                ],
                'synthetics' => [
                    'checks' => 'health=/health',
                ],
            ],
        ], $container);

        self::assertConsoleCommand($container, ShipLogsCommand::class, 'shared-hosting-observability:ship-logs');
        self::assertConsoleCommand($container, ShipTracesCommand::class, 'shared-hosting-observability:ship-traces');
        self::assertConsoleCommand($container, ShipMetricsCommand::class, 'shared-hosting-observability:ship-metrics');
        self::assertConsoleCommand($container, SyntheticCheckCommand::class, 'shared-hosting-observability:synthetic-check');
        self::assertConsoleCommand($container, TelemetryGcCommand::class, 'shared-hosting-observability:gc');
        self::assertTrue($container->hasAlias('shared_hosting_observability.trace_recorder'));
        self::assertTrue($container->hasAlias('shared_hosting_observability.metrics_registry'));

        $container->compile();

        self::assertTrue($container->has('shared_hosting_observability.trace_recorder'));
        self::assertTrue($container->has('shared_hosting_observability.metrics_registry'));
        self::assertSame(['health' => '/health'], $container->getParameter('shared_hosting_observability.synthetic_checks'));
    }

    private static function assertConsoleCommand(ContainerBuilder $container, string $serviceId, string $commandName): void
    {
        self::assertTrue($container->hasDefinition($serviceId));
        self::assertSame([['command' => $commandName]], $container->getDefinition($serviceId)->getTag('console.command'));
    }
}
