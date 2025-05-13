<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Shopware\PrometheusExporter\Metrics\Struct\Metric;
use Shopware\PrometheusExporter\Metrics\Struct\MetricValue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class QueueMetricProvider extends AbstractMetricProvider
{
    /**
     * @param ServiceLocator $transportLocator Locator for messenger transport services
     */
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceLocator $transportLocator
    ) {
    }

    /**
     * @return array<Metric>
     */
    public function getMetrics(): array
    {
        $values = [];
        
        foreach ($this->getTransportNames() as $name) {
            if (!$this->transportLocator->has($name)) {
                continue;
            }
            $transport = $this->transportLocator->get($name);

            if (!$transport instanceof MessageCountAwareInterface) {
                continue;
            }
            
            try {
                $count = $transport->getMessageCount();
                $values[] = new MetricValue((float) $count, ['queue' => $name]);
            } catch (\Throwable $e) {
                // Skip if we can't get the message count
            }
        }
        
        if (empty($values)) {
            return [];
        }
        
        return [
            $this->createMetric(
                'symfony_messenger_messages',
                $values,
                'Number of messages in the queue',
                Metric::TYPE_GAUGE
            )
        ];
    }

    /**
     * @return array<string>
     */
    private function getTransportNames(): array
    {
        $transportNames = array_keys($this->transportLocator->getProvidedServices());

        return array_filter($transportNames, static fn (string $transportName) => str_starts_with($transportName, 'messenger.transport'));
    }
}