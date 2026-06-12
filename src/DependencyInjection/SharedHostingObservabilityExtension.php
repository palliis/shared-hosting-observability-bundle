<?php

namespace Palliis\SharedHostingObservabilityBundle\DependencyInjection;

use Palliis\SharedHostingObservabilityBundle\Command\ShipLogsCommand;
use Palliis\SharedHostingObservabilityBundle\Command\ShipMetricsCommand;
use Palliis\SharedHostingObservabilityBundle\Command\ShipTracesCommand;
use Palliis\SharedHostingObservabilityBundle\Command\SyntheticCheckCommand;
use Palliis\SharedHostingObservabilityBundle\Command\TelemetryGcCommand;
use Palliis\SharedHostingObservabilityBundle\EventListener\RequestLoggingListener;
use Palliis\SharedHostingObservabilityBundle\EventListener\TraceRequestListener;
use Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry;
use Palliis\SharedHostingObservabilityBundle\Monolog\TraceLogProcessor;
use Palliis\SharedHostingObservabilityBundle\Telemetry\HttpHeaderParser;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpLogPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpMetricPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpProtobufLogPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpTracePayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceRecorder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceSpoolWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class SharedHostingObservabilityExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('shared_hosting_observability.service_name', $config['service_name']);
        $container->setParameter('shared_hosting_observability.service_namespace', $config['service_namespace']);
        $container->setParameter('shared_hosting_observability.environment', $config['environment']);
        $container->setParameter('shared_hosting_observability.metric_prefix', $config['metric_prefix']);
        $container->setParameter('shared_hosting_observability.synthetic_checks', $this->normalizeSyntheticChecks($config['synthetics']['checks']));

        $this->registerTelemetryServices($container, $config);
        $this->registerRequestLogging($container, $config);
        $this->registerMetrics($container, $config);
        $this->registerCommands($container, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerRequestLogging(ContainerBuilder $container, array $config): void
    {
        $logs = $config['logs'];
        if (!$logs['request_logging_enabled']) {
            return;
        }
        $loggerService = '' !== trim((string) $logs['request_logger_service'])
            ? (string) $logs['request_logger_service']
            : LoggerInterface::class;

        $container->setDefinition(RequestLoggingListener::class, (new Definition(RequestLoggingListener::class))
            ->setArguments([
                new Reference($loggerService),
                $logs['request_excluded_path_regex'],
                $logs['request_excluded_routes'],
                $logs['request_ignored_status_codes'],
            ])
            ->addTag('kernel.event_subscriber')
            ->setPublic(false)
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTelemetryServices(ContainerBuilder $container, array $config): void
    {
        $container->setDefinition(HttpHeaderParser::class, (new Definition(HttpHeaderParser::class))->setPublic(false));
        $container->setDefinition(OtlpTracePayloadBuilder::class, (new Definition(OtlpTracePayloadBuilder::class))->setPublic(false));
        $container->setDefinition(OtlpMetricPayloadBuilder::class, (new Definition(OtlpMetricPayloadBuilder::class))->setPublic(false));
        $container->setDefinition(OtlpLogPayloadBuilder::class, (new Definition(OtlpLogPayloadBuilder::class))
            ->setArguments([
                $config['service_name'],
                $config['environment'],
            ])
            ->setPublic(false)
        );
        $container->setDefinition(OtlpProtobufLogPayloadBuilder::class, (new Definition(OtlpProtobufLogPayloadBuilder::class))
            ->setArguments([
                $config['service_name'],
                $config['environment'],
            ])
            ->setPublic(false)
        );

        $traces = $config['traces'];
        $container->setDefinition(TraceSpoolWriter::class, (new Definition(TraceSpoolWriter::class))
            ->setArguments([
                '%kernel.project_dir%',
                $traces['spool_dir'],
                $traces['max_spool_bytes'],
            ])
            ->setPublic(false)
        );

        $container->setDefinition(TraceRecorder::class, (new Definition(TraceRecorder::class))
            ->setArguments([
                $traces['enabled'],
                $config['service_name'],
                $config['service_namespace'],
                $config['environment'],
                $traces['excluded_path_regex'],
                $traces['sample_rate'],
                $traces['error_sample_rate'],
                $traces['slow_threshold_ms'],
                $traces['max_spans'],
                new Reference(TraceSpoolWriter::class),
            ])
            ->setPublic(false)
        );

        $container->setAlias('shared_hosting_observability.trace_recorder', new Alias(TraceRecorder::class, true));

        $container->setDefinition(TraceRequestListener::class, (new Definition(TraceRequestListener::class))
            ->setArguments([
                new Reference(TraceRecorder::class),
                $traces['enabled'],
            ])
            ->addTag('kernel.event_subscriber')
            ->setPublic(false)
        );

        $container->setDefinition(TraceLogProcessor::class, (new Definition(TraceLogProcessor::class))
            ->setArguments([new Reference(TraceRecorder::class)])
            ->addTag('monolog.processor')
            ->setPublic(false)
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerMetrics(ContainerBuilder $container, array $config): void
    {
        $container->setDefinition(MetricsRegistry::class, (new Definition(MetricsRegistry::class))
            ->setArguments([
                new Reference($config['metrics']['cache_pool']),
                new Reference(TraceRecorder::class),
                $config['metric_prefix'],
            ])
            ->setPublic(true)
        );

        $container->setAlias('shared_hosting_observability.metrics_registry', new Alias(MetricsRegistry::class, true));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCommands(ContainerBuilder $container, array $config): void
    {
        $logs = $config['logs'];
        $traces = $config['traces'];
        $synthetics = $config['synthetics'];

        $container->setDefinition(ShipLogsCommand::class, (new Definition(ShipLogsCommand::class))
            ->setArguments([
                new Reference('http_client'),
                new Reference(OtlpLogPayloadBuilder::class),
                new Reference(OtlpProtobufLogPayloadBuilder::class),
                new Reference(HttpHeaderParser::class),
                '%kernel.project_dir%',
                $logs['source_path'],
                $logs['state_path'],
                $logs['otlp_endpoint'],
                $logs['otlp_headers'],
                $logs['otlp_bearer_token'],
                $logs['timeout_seconds'],
                $logs['max_lines'],
                $logs['max_bytes'],
                $logs['format'],
            ])
            ->addTag('console.command', ['command' => 'shared-hosting-observability:ship-logs'])
            ->setPublic(false)
        );

        $container->setDefinition(ShipTracesCommand::class, (new Definition(ShipTracesCommand::class))
            ->setArguments([
                new Reference('http_client'),
                new Reference(OtlpTracePayloadBuilder::class),
                new Reference(HttpHeaderParser::class),
                '%kernel.project_dir%',
                $traces['spool_dir'],
                $traces['otlp_endpoint'],
                $traces['otlp_headers'],
                $traces['otlp_bearer_token'],
                $traces['otlp_timeout_seconds'],
                $traces['ship_min_file_age_seconds'],
                $traces['ship_batch_size'],
            ])
            ->addTag('console.command', ['command' => 'shared-hosting-observability:ship-traces'])
            ->setPublic(false)
        );

        $container->setDefinition(ShipMetricsCommand::class, (new Definition(ShipMetricsCommand::class))
            ->setArguments([
                new Reference('http_client'),
                new Reference(MetricsRegistry::class),
                new Reference(OtlpMetricPayloadBuilder::class),
                new Reference(HttpHeaderParser::class),
                $config['metrics']['otlp_endpoint'],
                $config['metrics']['otlp_headers'],
                $config['metrics']['otlp_bearer_token'],
                $config['metrics']['otlp_timeout_seconds'],
                $config['service_name'],
                $config['environment'],
                $config['metric_prefix'],
            ])
            ->addTag('console.command', ['command' => 'shared-hosting-observability:ship-metrics'])
            ->setPublic(false)
        );

        $container->setDefinition(SyntheticCheckCommand::class, (new Definition(SyntheticCheckCommand::class))
            ->setArguments([
                new Reference('http_client'),
                new Reference(LoggerInterface::class),
                new Reference(MetricsRegistry::class),
                new Reference(TraceRecorder::class),
                $synthetics['base_url'],
                '%shared_hosting_observability.synthetic_checks%',
                $synthetics['api_key'],
                $synthetics['timeout_seconds'],
            ])
            ->addTag('console.command', ['command' => 'shared-hosting-observability:synthetic-check'])
            ->setPublic(false)
        );

        $container->setDefinition(TelemetryGcCommand::class, (new Definition(TelemetryGcCommand::class))
            ->setArguments([
                '%kernel.project_dir%',
                $traces['spool_dir'],
                $logs['source_path'],
                $logs['state_path'],
                $traces['gc_max_age_seconds'],
                $traces['gc_max_total_bytes'],
                $logs['gc_max_bytes'],
            ])
            ->addTag('console.command', ['command' => 'shared-hosting-observability:gc'])
            ->setPublic(false)
        );
    }

    /**
     * @return array<string, string>
     */
    private function normalizeSyntheticChecks(mixed $checks): array
    {
        if (is_array($checks)) {
            $normalized = [];
            foreach ($checks as $name => $pathOrUrl) {
                $name = trim((string) $name);
                $pathOrUrl = is_scalar($pathOrUrl) ? trim((string) $pathOrUrl) : '';
                if ('' !== $name && '' !== $pathOrUrl) {
                    $normalized[$name] = $pathOrUrl;
                }
            }

            return $normalized;
        }

        if (!is_string($checks)) {
            return [];
        }

        $normalized = [];
        foreach (explode(',', $checks) as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }

            if (str_contains($entry, '=')) {
                [$name, $pathOrUrl] = explode('=', $entry, 2);
                $name = trim($name);
                $pathOrUrl = trim($pathOrUrl);
            } else {
                $pathOrUrl = $entry;
                $name = str_replace(['/', '-'], '_', trim($entry, '/'));
            }

            if ('' !== $name && '' !== $pathOrUrl) {
                $normalized[$name] = $pathOrUrl;
            }
        }

        return $normalized;
    }
}
