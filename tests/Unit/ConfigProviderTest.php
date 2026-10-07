<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Unit;

use Contenir\PageCache\Laminas\Mvc\ConfigProvider;
use Contenir\PageCache\Laminas\Mvc\Factory\CacheStrategyFactory;
use Contenir\PageCache\Laminas\Mvc\Factory\FormCsrfDisableCacheFactory;
use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\PageCache\Laminas\Mvc\View\Helper\Delegator\FormElementDisableCacheDelegator;
use Contenir\PageCache\Laminas\Mvc\View\Helper\FormCsrfDisableCache;
use Laminas\Form\View\Helper\FormElement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('cache')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function combinesServicesPageCacheDefaultsAndViewHelpers(): void
    {
        $provider = new ConfigProvider();

        static::assertSame(
            [
                'service_manager' => $provider->getDependencies(),
                'pagecache'       => $provider->getPageCacheDefaults(),
                'view_helpers'    => $provider->getViewHelperConfig(),
            ],
            $provider(),
        );
    }

    #[Test]
    public function defaultsEveryPerRequestOptionToOffWithNoTtlOrPriority(): void
    {
        static::assertSame(
            [
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
            (new ConfigProvider())->getPageCacheDefaults()['options'],
        );
    }

    #[Test]
    public function defaultsToCachingOffWithCsrfProtectionOnAndNoBackend(): void
    {
        $defaults = (new ConfigProvider())->getPageCacheDefaults();

        static::assertSame(
            [null, true, false, []],
            [$defaults['cache'], $defaults['disable_on_csrf'], $defaults['options']['cache'], $defaults['routes']],
        );
    }

    #[Test]
    public function delegatesTheFormElementHelperAndRegistersTheCsrfHelper(): void
    {
        static::assertSame(
            [
                'delegators' => [FormElement::class => [FormElementDisableCacheDelegator::class]],
                'factories'  => [FormCsrfDisableCache::class => FormCsrfDisableCacheFactory::class],
            ],
            (new ConfigProvider())->getViewHelperConfig(),
        );
    }

    #[Test]
    public function registersTheListenerFactory(): void
    {
        static::assertSame(
            ['factories' => [CacheStrategy::class => CacheStrategyFactory::class]],
            (new ConfigProvider())->getDependencies(),
        );
    }
}
