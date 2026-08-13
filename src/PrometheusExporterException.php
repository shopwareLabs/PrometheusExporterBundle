<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

class PrometheusExporterException extends HttpException
{
    public const STORAGE_NOT_CONFIGURED = 'PROMETHEUS_EXPORTER__STORAGE_NOT_CONFIGURED';

    public const UNSUPPORTED_REDIS_CLIENT = 'PROMETHEUS_EXPORTER__UNSUPPORTED_REDIS_CLIENT';

    public static function storageNotConfigured(): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::STORAGE_NOT_CONFIGURED,
            'Metric storage is not configured. Set either prometheus_exporter.storage.dsn or prometheus_exporter.storage.redis_connection_name.'
        );
    }

    public static function unsupportedRedisClient(mixed $client): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::UNSUPPORTED_REDIS_CLIENT,
            'The configured Redis connection resolves to "{{ type }}", which the metric storage does not support. Supported clients: \Redis (phpredis) and Predis\Client. Point prometheus_exporter.storage.redis_connection_name to a compatible connection or configure prometheus_exporter.storage.dsn.',
            ['type' => \get_debug_type($client)]
        );
    }
}
