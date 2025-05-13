<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('prometheus_metrics');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('allowed_ips')
                    ->scalarPrototype()->end()
                    ->defaultValue(['127.0.0.1', '::1'])
                    ->info('List of IP addresses or CIDR ranges that are allowed to access the metrics endpoint')
                ->end()
            ->end();

        return $treeBuilder;
    }
}