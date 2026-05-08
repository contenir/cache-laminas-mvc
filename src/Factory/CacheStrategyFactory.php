<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Factory;

use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Storage\StorageInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Factory for the page-cache listener.
 *
 * Pulls per-event attachment config from config[events][CacheStrategy::class]
 * and the cache backend, options and route overrides from config[pagecache].
 * The master enable flag is config[pagecache][options][cache], which is
 * also the key consumed by any admin tool that writes pagecache.local.php
 * to flip caching at runtime.
 */
final class CacheStrategyFactory
{
    public function __invoke(ContainerInterface $container): CacheStrategy
    {
        $globalConfiguration = $container->has('config') ? $container->get('config') : [];

        $configuration = $globalConfiguration['events'][CacheStrategy::class] ?? [];

        $options = $globalConfiguration['pagecache'] ?? [];

        $cacheServiceId = $options['cache'] ?? null;
        if (! is_string($cacheServiceId) || $cacheServiceId === '') {
            throw new RuntimeException(
                'contenir/cache-laminas-mvc: config[pagecache][cache] must be the service ID of a'
                . ' Laminas\Cache\Storage\StorageInterface backend.'
            );
        }

        $storage = $container->get($cacheServiceId);
        if (! $storage instanceof StorageInterface) {
            throw new RuntimeException(sprintf(
                'contenir/cache-laminas-mvc: service "%s" must resolve to a Laminas\Cache\Storage\StorageInterface; got %s.',
                $cacheServiceId,
                is_object($storage) ? $storage::class : gettype($storage),
            ));
        }

        $listener = (new CacheStrategy($configuration))
            ->setCache($storage)
            ->setOptions($options['options'] ?? [])
            ->setRoutes($options['routes'] ?? []);

        if ($container->has(AuthenticationServiceInterface::class)) {
            $listener->setAuthenticationService($container->get(AuthenticationServiceInterface::class));
        }

        return $listener;
    }
}
