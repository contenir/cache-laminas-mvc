<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc;

/**
 * Returns the merged config consumed by Module::getConfig().
 *
 * Kept separate so a Mezzio sibling adapter can later require the same array
 * without reaching into a Laminas MVC Module class.
 */
final class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'service_manager' => $this->getDependencies(),
            'pagecache'       => $this->getPageCacheDefaults(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                Listener\CacheStrategy::class => Factory\CacheStrategyFactory::class,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageCacheDefaults(): array
    {
        return [
            // Service ID of the Laminas\Cache\Storage\StorageInterface backend
            // the listener should read and write through. Required at runtime.
            'cache'   => null,

            // Default per-request options. The `cache` flag is the master
            // enable switch; an admin tool flipping pagecache.local.php
            // overrides it without touching siblings.
            'options' => [
                'cache_with_query'     => false,
                'cache_with_post'      => false,
                'cache_with_session'   => false,
                'cache_with_files'     => false,
                'cache_with_cookie'    => false,
                'make_id_with_query'   => false,
                'make_id_with_post'    => false,
                'make_id_with_session' => false,
                'make_id_with_files'   => false,
                'make_id_with_cookie'  => false,
                'cache'                => false,
                'ttl'                  => null,
                'priority'             => null,
            ],

            // Route patterns (regex => options-overrides) layered on top of
            // the defaults at request time when the path matches.
            'routes'  => [],
        ];
    }
}
