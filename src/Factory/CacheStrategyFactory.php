<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Factory;

use Contenir\PageCache\CacheControlRepositoryInterface;
use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Storage\StorageInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function array_keys;
use function array_map;
use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Factory for the page-cache listener.
 *
 * Pulls per-event attachment config from config[events][CacheStrategy::class]
 * and the cache backend from config[pagecache][cache]. The cache state comes
 * from the container's CacheControlRepositoryInterface when one is
 * registered; otherwise from LayeredFileRepositoryFactory, which re-reads
 * the admin's file on every request and lays it over
 * config[pagecache][options] and config[pagecache][routes]. The master
 * enable flag is config[pagecache][options][cache].
 *
 * Malformed entries are skipped: event identifiers and event names that
 * are not strings, options without a string name, and routes whose
 * overrides are not an array.
 *
 * @api
 */
final class CacheStrategyFactory
{
    /**
     * @throws RuntimeException When the service is not a cache storage.
     */
    private static function assertStorage(string $serviceId, mixed $storage): StorageInterface
    {
        if ($storage instanceof StorageInterface) {
            return $storage;
        }

        throw new RuntimeException(sprintf(
            'contenir/contenir-page-cache-laminas-mvc: service "%s" must resolve to a Laminas\Cache\Storage\StorageInterface; got %s.',
            $serviceId,
            get_debug_type($storage),
        ));
    }

    /**
     * The string-keyed entries of $config[$key]; empty when that is not an array.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        return self::stringKeyed($config[$key] ?? null);
    }

    /**
     * @throws RuntimeException When the service ID is not a non-empty string or does not resolve to a cache storage.
     * @throws ContainerExceptionInterface When the storage service cannot be built.
     */
    private static function storage(ContainerInterface $container, mixed $serviceId): StorageInterface
    {
        if (! is_string($serviceId) || '' === $serviceId) {
            throw new RuntimeException(
                'contenir/contenir-page-cache-laminas-mvc: config[pagecache][cache] must be the service ID of a'
                    . ' Laminas\Cache\Storage\StorageInterface backend.',
            );
        }

        return self::assertStorage($serviceId, $container->get($serviceId));
    }

    /**
     * The string-keyed entries of an array; empty for anything else.
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $values): array
    {
        $values = is_array($values) ? $values : [];
        $named  = [];
        foreach (array_keys($values) as $key) {
            if (! is_string($key)) {
                continue;
            }

            $named[$key] = $values[$key] ?? null;
        }

        return $named;
    }

    /**
     * @throws RuntimeException When config[pagecache][cache] does not name a cache storage service.
     * @throws ContainerExceptionInterface When a service cannot be built.
     */
    public function __invoke(ContainerInterface $container): CacheStrategy
    {
        $config  = self::stringKeyed($container->has('config') ? $container->get('config') : []);
        $options = self::section($config, 'pagecache');

        $listener = (new CacheStrategy(array_map(
            self::stringKeyed(...),
            self::section(self::section($config, 'events'), CacheStrategy::class),
        )))->setCache(self::storage($container, $options['cache'] ?? null))
            ->setRepository(
                $container->has(CacheControlRepositoryInterface::class)
                    ? $container->get(CacheControlRepositoryInterface::class)
                    : (new LayeredFileRepositoryFactory())($container),
            );

        if ($container->has(AuthenticationServiceInterface::class)) {
            $listener->setAuthenticationService($container->get(AuthenticationServiceInterface::class));
        }

        return $listener;
    }
}
