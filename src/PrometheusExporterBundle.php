<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter;

use Shopware\Core\Framework\Bundle;
use Shopware\PrometheusExporter\DependencyInjection\CompilerPass\ScrapeProviderPass;
use Shopware\PrometheusExporter\DependencyInjection\PrometheusExporterExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

class PrometheusExporterBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ScrapeProviderPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new PrometheusExporterExtension();
    }
}
