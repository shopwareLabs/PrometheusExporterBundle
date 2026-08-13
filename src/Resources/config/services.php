<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Shopware\PrometheusExporter\Command\TestMetricsCommand;
use Shopware\PrometheusExporter\Controller\MetricsController;
use Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPFPMMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPInfoMetricProvider;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Controller
    $services->set(MetricsController::class)
        ->public()
        ->args([
            tagged_iterator('shopware.prometheus.metrics'),
            param('prometheus_exporter.endpoint.allowed_ips'),
        ])
        ->call('setContainer', [service('service_container')])
        ->tag('controller.service_arguments');

    // Scrape-time metric providers (opt-in via prometheus_exporter.scrape_providers)
    $services->set(PHPInfoMetricProvider::class)
        ->tag('shopware.prometheus.metrics');

    $services->set(PHPFPMMetricProvider::class)
        ->tag('shopware.prometheus.metrics');

    $services->set(OpenSearchMetricProvider::class)
        ->autowire()
        ->tag('shopware.prometheus.metrics');

    // Commands
    $services->set(TestMetricsCommand::class)
        ->args([service(MetricsController::class)])
        ->tag('console.command');
};
