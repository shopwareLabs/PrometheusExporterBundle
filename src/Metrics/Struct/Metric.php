<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics\Struct;

/**
 * Represents a Prometheus metric with its metadata and values
 */
class Metric
{
    public const TYPE_GAUGE = 'gauge';
    public const TYPE_COUNTER = 'counter';
    public const TYPE_HISTOGRAM = 'histogram';
    public const TYPE_SUMMARY = 'summary';

    /**
     * @param array<MetricValue> $values
     */
    public function __construct(
        private readonly string $name,
        private readonly array $values,
        private readonly string $help = '',
        private readonly string $type = self::TYPE_GAUGE,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<MetricValue>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getHelp(): string
    {
        return $this->help;
    }

    public function getType(): string
    {
        return $this->type;
    }
}