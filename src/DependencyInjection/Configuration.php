<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @internal
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('prometheus_exporter');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            // root-level on purpose: a validate() on the storage node itself is skipped
            // when the "storage" key is absent (defaults bypass node validation)
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['storage']['dsn'] === null && $config['storage']['redis_connection_name'] === null)
                ->thenInvalid('storage: either "dsn" or "redis_connection_name" must be configured')
            ->end()
            ->children()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('redis_connection_name')
                            ->defaultNull()
                            ->info('Name of a connection under shopware.redis.connections; must resolve to a phpredis \Redis or a Predis\Client instance')
                        ->end()
                        ->scalarNode('dsn')
                            ->defaultNull()
                            ->info('Redis DSN for a dedicated storage connection; takes priority over redis_connection_name')
                        ->end()
                        ->scalarNode('key_prefix')
                            ->defaultValue('sw:metrics')
                            ->info('Key prefix for all metric series stored in Redis')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('transport')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('write_mode')
                            ->values(['buffered', 'direct'])
                            ->defaultValue('buffered')
                            ->info('"buffered" writes to Redis once per request/worker flush; "direct" writes on every emission')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('endpoint')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allowed_ips')
                            ->scalarPrototype()->end()
                            ->defaultValue(['127.0.0.1', '::1'])
                            ->info('IP addresses or CIDR ranges allowed to access the metrics endpoint; empty list disables the check')
                        ->end()
                        ->scalarNode('auth_token')
                            ->defaultNull()
                            ->info('When set, scrapes must send "Authorization: Bearer <token>"')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('scrape_providers')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->booleanPrototype()->end()
                    ->info('Enable scrape-time metric providers by name (built-in: instance, opcache, php_fpm, opensearch); all are disabled by default.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
