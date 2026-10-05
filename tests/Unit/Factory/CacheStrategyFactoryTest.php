<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\Cache\Laminas\Mvc\Factory\CacheStrategyFactory;
use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\Cache\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\Mvc\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

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
                ->setOptions(['cache' => true, 'ttl' => 600])
                ->setRoutes(['^/api' => ['cache' => false]]),
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
        $this->expectExceptionMessage('config[pagecache][cache] must be the service ID');

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
                ->setOptions(['cache' => true])
                ->setRoutes(['^/api' => ['cache' => false], 404 => ['cache' => false]]),
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
                ->setAuthenticationService($auth),
            $listener,
        );
    }
}
