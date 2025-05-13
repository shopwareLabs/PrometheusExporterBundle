<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter;

use Shopware\PrometheusExporter\DependencyInjection\PrometheusExporterExtension;
use Shopware\Core\Framework\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class PrometheusExporterBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(Metrics\MetricProviderInterface::class)
            ->addTag('shopware.prometheus.metrics');
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new PrometheusExporterExtension();
    }
}