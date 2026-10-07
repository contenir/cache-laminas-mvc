<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Factory;

use Contenir\PageCache\Repository\LayeredFileRepository;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

use function getcwd;
use function is_array;
use function is_string;

/**
 * Builds the cache-state repository CacheStrategy reads when the container
 * registers no CacheControlRepositoryInterface of its own.
 *
 * The admin's file is config[pagecache][file], or DEFAULT_FILE under the
 * working directory. The Laminas skeleton's public/index.php changes to the
 * application root, so that is usually the same place; set
 * config[pagecache][file] where it is not. The file is re-read on every
 * request, including cache hits, and laid over config[pagecache][options]
 * and config[pagecache][routes]. A missing or unreadable file, or sections
 * that are not arrays, leave the site's settings in force.
 *
 * @api
 */
final class LayeredFileRepositoryFactory
{
    /**
     * Where the admin's overrides live when `pagecache.file` is not set,
     * relative to the working directory.
     */
    public const string DEFAULT_FILE = 'config/autoload/pagecache.local.php';

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function filePath(mixed $configured): string
    {
        if (is_string($configured) && '' !== $configured) {
            return $configured;
        }

        $cwd = getcwd();

        return (false === $cwd ? '.' : $cwd) . '/' . self::DEFAULT_FILE;
    }

    /**
     * @throws ContainerExceptionInterface When the config service cannot be built.
     */
    public function __invoke(ContainerInterface $container): LayeredFileRepository
    {
        $config    = self::arrayOrEmpty($container->has('config') ? $container->get('config') : null);
        $pagecache = self::arrayOrEmpty($config['pagecache'] ?? null);

        return LayeredFileRepository::withDefaults(
            self::filePath($pagecache['file'] ?? null),
            $pagecache['options'] ?? null,
            $pagecache['routes'] ?? null,
        );
    }
}
