<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Controller;

use Shopware\PrometheusExporter\Metrics\MetricProviderInterface;
use Shopware\PrometheusExporter\Metrics\Struct\Metric;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class MetricsController extends AbstractController
{
    /**
     * @param iterable<MetricProviderInterface> $metricProviders
     * @param array<string> $allowedIps
     */
    public function __construct(
        #[TaggedIterator('shopware.prometheus.metrics')]
        private readonly iterable $metricProviders,
        private readonly array $allowedIps,
    ) {
    }

    #[Route(path: '/api/_internal/prometheus', name: 'prometheus.metrics', methods: ['GET'], defaults: ['auth_required' => false])]
    public function metrics(Request $request): Response
    {
        // Check IP restriction
        $clientIp = $request->getClientIp();
        if ($clientIp === null || !$this->isIpAllowed($clientIp)) {
            return new Response('Access denied', Response::HTTP_FORBIDDEN);
        }
        
        $allMetrics = [];
        
        foreach ($this->metricProviders as $provider) {
            $metrics = $provider->getMetrics();
            
            foreach ($metrics as $metric) {
                $allMetrics[] = $metric;
            }
        }
        
        $response = new Response($this->formatMetrics($allMetrics));
        $response->headers->set('Content-Type', 'text/plain; version=0.0.4');
        
        return $response;
    }
    
    /**
     * Check if the given IP is allowed to access the metrics endpoint
     */
    private function isIpAllowed(string $ip): bool
    {
        // Make sure localhost is always allowed
        $allowedIps = array_merge($this->allowedIps, ['127.0.0.1', '::1', 'localhost']);
        return IpUtils::checkIp($ip, $allowedIps);
    }
    
    /**
     * @param array<Metric> $metrics
     */
    private function formatMetrics(array $metrics): string
    {
        $lines = [];
        
        foreach ($metrics as $metric) {
            $name = $metric->getName();
            $help = $metric->getHelp();
            $type = $metric->getType();
            
            // Add metric header lines
            $lines[] = "# HELP $name $help";
            $lines[] = "# TYPE $name $type";
            
            // Add metric values
            foreach ($metric->getValues() as $value) {
                $labelString = '';
                $labels = $value->getLabels();
                
                if (!empty($labels)) {
                    $labelParts = [];
                    
                    foreach ($labels as $labelName => $labelValue) {
                        // Ensure labelValue is a string
                        $labelValueString = (string) $labelValue;
                        $labelParts[] = $labelName . '="' . str_replace('"', '\\"', $labelValueString) . '"';
                    }
                    
                    $labelString = '{' . implode(',', $labelParts) . '}';
                }
                
                $lines[] = $name . $labelString . ' ' . $value->getValue();
            }
            
            // Add empty line between metrics
            $lines[] = '';
        }
        
        return implode("\n", $lines);
    }
}