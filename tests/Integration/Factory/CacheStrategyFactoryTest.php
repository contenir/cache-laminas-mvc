<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Integration\Factory;

use Contenir\Cache\Laminas\Mvc\ConfigProvider;
use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Mvc\Application;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\ArrayUtils;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('cache')]
final class CacheStrategyFactoryTest extends TestCase
{
    #[Test]
    public function buildsTheListenerFromMergedModuleAndSiteConfiguration(): void
    {
        $storage  = new Memory();
        $services = $this->services($storage);

        static::assertEquals(
            (new CacheStrategy([Application::class => ['dispatch' => -100, 'finish' => 100]]))->setCache($storage)
                ->setOptions([
                    ...(new ConfigProvider())->getPageCacheDefaults()['options'],
                    'cache' => true,
                    'ttl'   => 600,
                ])
                ->setRoutes(['^/api' => ['cache' => false]]),
            $services->get(CacheStrategy::class),
        );
    }

    #[Test]
    public function wiresTheRegisteredAuthenticationService(): void
    {
        $storage = new Memory();
        $auth    = new AuthenticationService();

        static::assertEquals(
            (new CacheStrategy([Application::class => ['dispatch' => -100, 'finish' => 100]]))->setCache($storage)
                ->setOptions([
                    ...(new ConfigProvider())->getPageCacheDefaults()['options'],
                    'cache' => true,
                    'ttl'   => 600,
                ])
                ->setRoutes(['^/api' => ['cache' => false]])
                ->setAuthenticationService($auth),
            $this->services($storage, [AuthenticationServiceInterface::class => $auth])->get(CacheStrategy::class),
        );
    }

    /**
     * @param array<string, object> $extra
     */
    private function services(Memory $storage, array $extra = []): ServiceManager
    {
        $provider = new ConfigProvider();
        $config   = ArrayUtils::merge($provider(), [
            'events'    => [CacheStrategy::class => [Application::class => ['dispatch' => -100, 'finish' => 100]]],
            'pagecache' => [
                'cache'   => 'cache.page',
                'options' => ['cache' => true, 'ttl' => 600],
                'routes'  => ['^/api' => ['cache' => false]],
            ],
        ]);

        return new ServiceManager([
            ...$provider->getDependencies(),
            'services' => ['config' => $config, 'cache.page' => $storage, ...$extra],
        ]);
    }
}
