<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\PrometheusExporter\Command\ListScrapeProvidersCommand;
use Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPFPMMetricProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(ListScrapeProvidersCommand::class)]
class ListScrapeProvidersCommandTest extends TestCase
{
    public function testListsProvidersWithTheirToggleState(): void
    {
        $tester = new CommandTester(new ListScrapeProvidersCommand(
            [
                'php_fpm' => PHPFPMMetricProvider::class,
                'opensearch' => OpenSearchMetricProvider::class,
            ],
            ['php_fpm' => true],
        ));

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        static::assertSame(Command::SUCCESS, $exitCode);
        static::assertMatchesRegularExpression('/php_fpm\s+\S+PHPFPMMetricProvider\s+yes/', $display);
        static::assertMatchesRegularExpression('/opensearch\s+\S+OpenSearchMetricProvider\s+no/', $display);
    }

    public function testWarnsWhenNoProvidersAreRegistered(): void
    {
        $tester = new CommandTester(new ListScrapeProvidersCommand([], []));

        $exitCode = $tester->execute([]);

        static::assertSame(Command::SUCCESS, $exitCode);
        static::assertStringContainsString('No scrape-time metric providers are registered.', $tester->getDisplay());
    }
}
