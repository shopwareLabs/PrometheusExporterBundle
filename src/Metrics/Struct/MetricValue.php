<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics\Struct;

/**
 * @internal
 *
 * Represents a single metric value with optional labels
 */
class MetricValue
{
    /**
     * @param array<string, string|int|float|bool> $labels
     */
    public function __construct(
        private readonly float $value,
        private readonly array $labels = []
    ) {
    }

    public function getValue(): float
    {
        return $this->value;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }
}