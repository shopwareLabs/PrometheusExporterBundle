<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Log\NullLogger;
use Shopware\PrometheusExporter\Controller\MetricsController;
use Shopware\PrometheusExporter\Metrics\MetricsCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(MetricsController::class)]
#[UsesClass(MetricsCollector::class)]
class MetricsControllerTest extends TestCase
{
    private CollectorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);
    }

    public function testMissingOrWrongTokenIsRejected(): void
    {
        $controller = $this->createController(authToken: 'secret', allowedIps: []);

        static::assertSame(Response::HTTP_UNAUTHORIZED, $controller->metrics($this->request())->getStatusCode());
        static::assertSame(Response::HTTP_UNAUTHORIZED, $controller->metrics($this->request(token: 'wrong'))->getStatusCode());
        static::assertSame(Response::HTTP_OK, $controller->metrics($this->request(token: 'secret'))->getStatusCode());
    }

    public function testIpOutsideAllowlistIsRejected(): void
    {
        $controller = $this->createController(allowedIps: ['127.0.0.1']);

        static::assertSame(Response::HTTP_FORBIDDEN, $controller->metrics($this->request(ip: '10.0.0.1'))->getStatusCode());
        static::assertSame(Response::HTTP_OK, $controller->metrics($this->request(ip: '127.0.0.1'))->getStatusCode());
    }

    public function testEveryConfiguredCheckMustPass(): void
    {
        $controller = $this->createController(authToken: 'secret', allowedIps: ['127.0.0.1']);

        static::assertSame(Response::HTTP_UNAUTHORIZED, $controller->metrics($this->request(ip: '127.0.0.1'))->getStatusCode());
        static::assertSame(Response::HTTP_FORBIDDEN, $controller->metrics($this->request(token: 'secret', ip: '10.0.0.1'))->getStatusCode());
        static::assertSame(Response::HTTP_OK, $controller->metrics($this->request(token: 'secret', ip: '127.0.0.1'))->getStatusCode());
    }

    public function testUnprotectedEndpointStillResponds(): void
    {
        $controller = $this->createController(allowedIps: []);

        static::assertSame(Response::HTTP_OK, $controller->metrics($this->request())->getStatusCode());
    }

    public function testRespondsWithRenderedMetricsAndPrometheusContentType(): void
    {
        $this->registry->getOrRegisterCounter('', 'stored_metric', 'stored')->incBy(3);

        $response = $this->createController()->metrics($this->request(ip: '127.0.0.1'));

        static::assertStringContainsString('text/plain; version=0.0.4', (string) $response->headers->get('Content-Type'));
        static::assertStringContainsString('stored_metric 3', (string) $response->getContent());
    }

    /**
     * @param array<string> $allowedIps
     */
    private function createController(
        array $allowedIps = ['127.0.0.1'],
        ?string $authToken = null,
    ): MetricsController {
        return new MetricsController(
            new MetricsCollector($this->registry, [], new NullLogger()),
            $allowedIps,
            $authToken,
        );
    }

    private function request(?string $token = null, string $ip = '127.0.0.1'): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        return Request::create('/api/_internal/prometheus', Request::METHOD_GET, server: $server);
    }
}
