<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\DependencyInjection\CompilerPass;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Applies the prometheus_exporter.scrape_providers toggles: every service tagged with
 * shopware.prometheus.metrics declares its toggle name in the "provider" tag attribute
 * and is removed from the container unless enabled in the configuration.
 *
 * Provider names must be unique, and bare names are reserved for this bundle's built-in
 * providers — a service from any other namespace must use a vendor-prefixed name
 * ("<vendor>.<name>"). Providers are not an official extension point yet (see
 * MetricProviderInterface); the guards exist to fail early if a plugin taps into the tag anyway.
 *
 * This runs as a compiler pass on purpose: Shopware's Bundle::build() loads the bundle's
 * services.php into the main container, so removing definitions at extension-load time
 * (temporary container) has no effect.
 *
 * @internal
 */
class ScrapeProviderPass implements CompilerPassInterface
{
    final public const TAG = 'shopware.prometheus.metrics';

    private const PARAMETER = 'prometheus_exporter.scrape_providers';

    private const BUNDLE_NAMESPACE = 'Shopware\\PrometheusExporter\\';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, bool> $config */
        $config = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : [];

        $providers = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $tags) {
            $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;

            foreach ($tags as $attributes) {
                $name = $attributes['provider'] ?? null;
                if (!\is_string($name) || $name === '') {
                    throw new InvalidArgumentException(\sprintf(
                        'The "%s" tag on service "%s" requires a "provider" attribute carrying the scrape provider name.',
                        self::TAG,
                        $serviceId,
                    ));
                }

                if (!\str_starts_with($class, self::BUNDLE_NAMESPACE) && !\str_contains($name, '.')) {
                    throw new InvalidArgumentException(\sprintf(
                        'Scrape provider name "%s" on service "%s" (%s) is not allowed: bare names are reserved for built-in providers; use a vendor-prefixed name such as "<vendor>.%s".',
                        $name,
                        $serviceId,
                        $class,
                        $name,
                    ));
                }

                if (isset($providers[$name]) && $providers[$name] !== $serviceId) {
                    throw new InvalidArgumentException(\sprintf(
                        'Scrape provider name "%s" is claimed by both "%s" and "%s"; provider names must be unique.',
                        $name,
                        $providers[$name],
                        $serviceId,
                    ));
                }

                $providers[$name] = $serviceId;
            }
        }

        $unknown = \array_diff_key($config, $providers);
        if ($unknown !== []) {
            $known = [];
            foreach ($providers as $name => $serviceId) {
                $known[] = \sprintf('"%s" (%s)', $name, $container->getDefinition($serviceId)->getClass() ?? $serviceId);
            }

            throw new InvalidConfigurationException(\sprintf(
                'Unknown scrape provider(s) "%s" under prometheus_exporter.scrape_providers. Known providers: %s.',
                \implode('", "', \array_keys($unknown)),
                \implode(', ', $known),
            ));
        }

        foreach ($providers as $name => $serviceId) {
            if ($config[$name] ?? false) {
                continue;
            }

            $container->removeDefinition($serviceId);
        }
    }
}
