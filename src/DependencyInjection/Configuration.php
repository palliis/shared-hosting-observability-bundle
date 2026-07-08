<?php

namespace Palliis\SharedHostingObservabilityBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('shared_hosting_observability');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('service_name')->defaultValue('symfony-app')->end()
                ->scalarNode('service_namespace')->defaultValue('shared-hosting')->end()
                ->scalarNode('environment')->defaultValue('%kernel.environment%')->end()
                ->scalarNode('metric_prefix')->defaultValue('sho')->end()
                ->arrayNode('traces')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('spool_dir')->defaultValue('var/telemetry/traces')->end()
                        ->scalarNode('excluded_path_regex')->defaultValue('#^/(health|metrics|_profiler)#')->end()
                        ->floatNode('sample_rate')->defaultValue(0.01)->min(0)->max(1)->end()
                        ->floatNode('error_sample_rate')->defaultValue(1.0)->min(0)->max(1)->end()
                        ->integerNode('slow_threshold_ms')->defaultValue(2500)->min(0)->end()
                        ->integerNode('max_spans')->defaultValue(250)->min(0)->end()
                        ->integerNode('max_spool_bytes')->defaultValue(52428800)->min(0)->end()
                        ->scalarNode('otlp_endpoint')->defaultValue('')->end()
                        ->scalarNode('otlp_headers')->defaultValue('')->end()
                        ->scalarNode('otlp_bearer_token')->defaultValue('')->end()
                        ->floatNode('otlp_timeout_seconds')->defaultValue(5.0)->min(0.1)->end()
                        ->integerNode('ship_min_file_age_seconds')->defaultValue(60)->min(0)->end()
                        ->integerNode('ship_batch_size')->defaultValue(100)->min(1)->end()
                        ->integerNode('gc_max_age_seconds')->defaultValue(7200)->min(0)->end()
                        ->integerNode('gc_max_total_bytes')->defaultValue(52428800)->min(0)->end()
                    ->end()
                ->end()
                ->arrayNode('logs')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('source_path')->defaultValue('%kernel.logs_dir%/%kernel.environment%.log')->end()
                        ->scalarNode('state_path')->defaultValue('var/telemetry/state/logs.offset.json')->end()
                        ->scalarNode('otlp_endpoint')->defaultValue('')->end()
                        ->scalarNode('otlp_headers')->defaultValue('')->end()
                        ->scalarNode('otlp_bearer_token')->defaultValue('')->end()
                        ->scalarNode('format')->defaultValue('otlp_protobuf')->end()
                        ->floatNode('timeout_seconds')->defaultValue(5.0)->min(0.1)->end()
                        ->integerNode('max_lines')->defaultValue(1000)->min(1)->end()
                        ->integerNode('max_bytes')->defaultValue(1000000)->min(1024)->end()
                        ->integerNode('gc_max_bytes')->defaultValue(52428800)->min(0)->end()
                        ->booleanNode('request_logging_enabled')->defaultFalse()->end()
                        ->scalarNode('request_logger_service')->defaultValue('')->end()
                        ->scalarNode('request_excluded_path_regex')->defaultValue('#^/(health|metrics|_profiler)#')->end()
                        ->arrayNode('request_excluded_routes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('request_ignored_status_codes')
                            ->integerPrototype()->end()
                            ->defaultValue([404, 405])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('synthetics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_url')->defaultValue('')->end()
                        ->scalarNode('api_key')->defaultValue('')->end()
                        ->floatNode('timeout_seconds')->defaultValue(8.0)->min(0.1)->end()
                        ->variableNode('checks')->defaultValue([])->end()
                    ->end()
                ->end()
                ->arrayNode('metrics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')->defaultValue('cache.app')->end()
                        ->scalarNode('lock_path')->defaultValue('var/telemetry/state/metrics.lock')->end()
                        ->scalarNode('otlp_endpoint')->defaultValue('')->end()
                        ->scalarNode('otlp_headers')->defaultValue('')->end()
                        ->scalarNode('otlp_bearer_token')->defaultValue('')->end()
                        ->floatNode('otlp_timeout_seconds')->defaultValue(5.0)->min(0.1)->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
