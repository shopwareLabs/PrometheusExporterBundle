<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Log\NullLogger;
use Shopware\PrometheusExporter\Command\TestMetricsCommand;
use Shopware\PrometheusExporter\Controller\MetricsController;
use Shopware\PrometheusExporter\Metrics\MetricsCollector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(TestMetricsCommand::class)]
#[UsesClass(MetricsController::class)]
#[UsesClass(MetricsCollector::class)]
class TestMetricsCommandTest extends TestCase
{
    private CollectorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);
    }

    public function testPrintsMetricsWhenTheConfiguredTokenPassesTheGuard(): void
    {
        $this->registry->getOrRegisterCounter('', 'stored_metric', 'stored')->incBy(3);

        $tester = $this->createTester(allowedIps: ['127.0.0.1'], authToken: 'secret');
        $exitCode = $tester->execute([]);

        static::assertSame(Command::SUCCESS, $exitCode);
        static::assertStringContainsString('stored_metric 3', $tester->getDisplay());
    }

    public function testFailsWithWrongTokenOverride(): void
    {
        $tester = $this->createTester(allowedIps: ['127.0.0.1'], authToken: 'secret');
        $exitCode = $tester->execute(['--token' => 'wrong']);

        static::assertSame(Command::FAILURE, $exitCode);
        static::assertStringContainsString('status 401', $tester->getDisplay());
        static::assertStringContainsString('auth_token guard', $tester->getDisplay());
    }

    public function testFailsWhenTheDefaultClientIpIsNotAllowed(): void
    {
        $tester = $this->createTester(allowedIps: ['10.2.0.0/16']);
        $exitCode = $tester->execute([]);

        static::assertSame(Command::FAILURE, $exitCode);
        static::assertStringContainsString('status 403', $tester->getDisplay());
        static::assertStringContainsString('allowed_ips guard', $tester->getDisplay());
    }

    public function testSimulatesAnAllowedScraperIp(): void
    {
        $tester = $this->createTester(allowedIps: ['10.2.0.0/16']);
        $exitCode = $tester->execute(['--ip' => '10.2.3.4']);

        static::assertSame(Command::SUCCESS, $exitCode);
        static::assertStringContainsString('Metrics endpoint is working correctly.', $tester->getDisplay());
        static::assertStringNotContainsString('without auth_token or allowed_ips restriction', $this->unwrap($tester->getDisplay()));
    }

    public function testWarnsWhenNeitherGuardIsConfigured(): void
    {
        $tester = $this->createTester(allowedIps: []);
        $exitCode = $tester->execute([]);

        static::assertSame(Command::SUCCESS, $exitCode);
        static::assertStringContainsString('without auth_token or allowed_ips restriction', $this->unwrap($tester->getDisplay()));
    }

    /**
     * SymfonyStyle blocks hard-wrap at the terminal width; collapse the wrapping
     * so assertions can match a phrase regardless of where the break lands.
     */
    private function unwrap(string $display): string
    {
        return (string) \preg_replace('/\s+/', ' ', $display);
    }

    /**
     * @param array<string> $allowedIps
     */
    private function createTester(array $allowedIps, ?string $authToken = null): CommandTester
    {
        $controller = new MetricsController(
            new MetricsCollector($this->registry, [], new NullLogger()),
            $allowedIps,
            $authToken,
        );

        return new CommandTester(new TestMetricsCommand($controller, $allowedIps, $authToken));
    }
}
