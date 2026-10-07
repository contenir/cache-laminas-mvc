<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\PageCache\CacheControl;
use Contenir\PageCache\CacheControlRepositoryInterface;
use Contenir\PageCache\Laminas\Mvc\Factory\CacheStrategyFactory;
use Contenir\PageCache\Laminas\Mvc\Factory\LayeredFileRepositoryFactory;
use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\PageCache\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\PageCache\Repository\InMemoryRepository;
use Contenir\PageCache\Repository\LayeredFileRepository;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\Mvc\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function getcwd;

#[Group('unit')]
#[Group('cache')]
final class CacheStrategyFactoryTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingBackendProvider(): array
    {
        return [
            'no config service'         => [[]],
            'config is not an array'    => [['config' => 'nope']],
            'no pagecache key'          => [['config' => []]],
            'pagecache not an array'    => [['config' => ['pagecache' => true]]],
            'cache key missing'         => [['config' => ['pagecache' => []]]],
            'cache key not a string'    => [['config' => ['pagecache' => ['cache' => 42]]]],
            'cache key an empty string' => [['config' => ['pagecache' => ['cache' => '']]]],
        ];
    }

    /**
     * The repository the factory builds when the container registers none and
     * `pagecache.file` is not set.
     */
    private static function defaultRepository(CacheControl $defaults = new CacheControl(false)): LayeredFileRepository
    {
        return new LayeredFileRepository(getcwd() . '/' . LayeredFileRepositoryFactory::DEFAULT_FILE, $defaults);
    }

    #[Test]
    public function buildsTheListenerFromEventsOptionsAndRoutes(): void
    {
        $storage = $this->createStub(StorageInterface::class);

        $listener = (new CacheStrategyFactory())(new InMemoryContainer([
            'config'     => [
                'events'    => [CacheStrategy::class => [Application::class => ['dispatch' => -100, 'finish' => 100]]],
                'pagecache' => [
                    'cache'   => 'cache.page',
                    'options' => ['cache' => true, 'ttl' => 600],
                    'routes'  => ['^/api' => ['cache' => false]],
                ],
            ],
            'cache.page' => $storage,
        ]));

        static::assertEquals(
            (new CacheStrategy([Application::class => ['dispatch' => -100, 'finish' => 100]]))->setCache($storage)
                ->setRepository(self::defaultRepository(
                    new CacheControl(true, ['ttl' => 600], ['^/api' => ['cache' => false]]),
                )),
            $listener,
        );
    }

    #[Test]
    public function readsCacheStateFromTheRepositoryTheContainerRegisters(): void
    {
        $storage    = $this->createStub(StorageInterface::class);
        $repository = new InMemoryRepository(CacheControl::enabled());

        $listener = (new CacheStrategyFactory())(new InMemoryContainer([
            'config'                               => [
                'pagecache' => ['cache' => 'cache.page', 'options' => ['cache' => false]],
            ],
            'cache.page'                           => $storage,
            CacheControlRepositoryInterface::class => $repository,
        ]));

        static::assertEquals(
            (new CacheStrategy([]))->setCache($storage)
                ->setRepository($repository),
            $listener,
        );
    }

    #[Test]
    public function requiresTheBackendServiceToBeACacheStorage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'service "cache.page" must resolve to a Laminas\Cache\Storage\StorageInterface; got stdClass.',
        );

        (new CacheStrategyFactory())(new InMemoryContainer([
            'config'     => ['pagecache' => ['cache' => 'cache.page']],
            'cache.page' => new stdClass(),
        ]));
    }

    /**
     * @param array<string, mixed> $services
     */
    #[Test]
    #[DataProvider('missingBackendProvider')]
    public function requiresTheCacheBackendServiceId(array $services): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'contenir/contenir-page-cache-laminas-mvc: config[pagecache][cache] must be the service ID of a'
                . ' Laminas\\Cache\\Storage\\StorageInterface backend.',
        );

        (new CacheStrategyFactory())(new InMemoryContainer($services));
    }

    #[Test]
    public function skipsMalformedEventsOptionsAndRoutes(): void
    {
        $storage = $this->createStub(StorageInterface::class);

        $listener = (new CacheStrategyFactory())(new InMemoryContainer([
            'config'     => [
                'events'    => [
                    CacheStrategy::class => [
                        Application::class => ['dispatch' => 1, 0 => 'finish'],
                        0                  => ['dispatch' => 1],
                        'Other'            => 'dispatch',
                    ],
                ],
                'pagecache' => [
                    'cache'   => 'cache.page',
                    'options' => ['cache' => true, 0 => 'stray'],
                    'routes'  => [
                        '^/api'  => ['cache' => false, 0 => 'stray'],
                        '^/flag' => false,
                        404      => ['cache' => false],
                    ],
                ],
            ],
            'cache.page' => $storage,
        ]));

        static::assertEquals(
            (new CacheStrategy([Application::class => ['dispatch' => 1], 'Other' => []]))->setCache($storage)
                ->setRepository(self::defaultRepository(
                    new CacheControl(
                        true,
                        [],
                        [
                            '^/api' => ['cache' => false],
                            '404'   => ['cache' => false],
                        ],
                    ),
                )),
            $listener,
        );
    }

    #[Test]
    public function wiresTheAuthenticationServiceWhenOneIsRegistered(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $auth    = $this->createStub(AuthenticationServiceInterface::class);

        $listener = (new CacheStrategyFactory())(new InMemoryContainer([
            'config'                              => ['pagecache' => ['cache' => 'cache.page']],
            'cache.page'                          => $storage,
            AuthenticationServiceInterface::class => $auth,
        ]));

        static::assertEquals(
            (new CacheStrategy([]))->setCache($storage)
                ->setRepository(self::defaultRepository())
                ->setAuthenticationService($auth),
            $listener,
        );
    }
}
