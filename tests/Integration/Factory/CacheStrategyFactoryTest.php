<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Integration\Factory;

use Contenir\PageCache\CacheControl;
use Contenir\PageCache\Laminas\Mvc\ConfigProvider;
use Contenir\PageCache\Laminas\Mvc\Factory\LayeredFileRepositoryFactory;
use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\PageCache\Repository\LayeredFileRepository;
use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\Mvc\Application;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\ArrayUtils;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getcwd;

#[Group('integration')]
#[Group('cache')]
final class CacheStrategyFactoryTest extends TestCase
{
    /**
     * The repository built from the merged module defaults and site options,
     * over the default admin file.
     */
    private static function repository(): LayeredFileRepository
    {
        $options = (new ConfigProvider())->getPageCacheDefaults()['options'];
        unset($options['cache']);

        return new LayeredFileRepository(
            getcwd() . '/' . LayeredFileRepositoryFactory::DEFAULT_FILE,
            new CacheControl(true, [...$options, 'ttl' => 600], ['^/api' => ['cache' => false]]),
        );
    }

    #[Test]
    public function buildsTheListenerFromMergedModuleAndSiteConfiguration(): void
    {
        $storage  = new Memory();
        $services = $this->services($storage);

        static::assertEquals(
            (new CacheStrategy([Application::class => ['dispatch' => -100, 'finish' => 100]]))->setCache($storage)
                ->setRepository(self::repository()),
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
                ->setRepository(self::repository())
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
