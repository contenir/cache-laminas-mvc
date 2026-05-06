<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Factory;

use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\Authentication\AuthenticationService;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Factory for the page-cache listener.
 *
 * Pulls per-event attachment config from config['events'][CacheStrategy::class]
 * (matches the legacy in-Site shape) and the cache backend / options / routes
 * from config['pagecache']. The master enable flag is just
 * config[pagecache][options][cache] — same key admin's pagecache.local.php
 * writes to.
 */
final class CacheStrategyFactory
{
    public function __invoke(ContainerInterface $container): CacheStrategy
    {
        $globalConfiguration = $container->has('config') ? $container->get('config') : [];

        $configuration = $globalConfiguration['events'][CacheStrategy::class] ?? [];

        $authService = $container->get(AuthenticationService::class);

        $options = $globalConfiguration['pagecache'] ?? [];

        $cacheServiceId = $options['cache'] ?? null;
        if (! is_string($cacheServiceId) || $cacheServiceId === '') {
            throw new RuntimeException(
                'contenir/cache-laminas-mvc: config[pagecache][cache] must be the service ID of a'
                . ' Laminas\Cache\Storage\StorageInterface backend.'
            );
        }

        $outputCacheStorage = $container->get($cacheServiceId);
        $outputCache        = new SimpleCacheDecorator($outputCacheStorage);

        return (new CacheStrategy($authService, $configuration))
            ->setCache($outputCache)
            ->setOptions($options['options'] ?? [])
            ->setRoutes($options['routes'] ?? []);
    }
}
