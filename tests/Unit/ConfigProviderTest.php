<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Unit;

use Contenir\Cache\Laminas\Mvc\ConfigProvider;
use Contenir\Cache\Laminas\Mvc\Factory\CacheStrategyFactory;
use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\Cache\Laminas\Mvc\View\Helper\Delegator\FormElementDisableCacheDelegator;
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
    public function defaultsToCachingOffWithCsrfProtectionOnAndNoBackend(): void
    {
        $defaults = (new ConfigProvider())->getPageCacheDefaults();

        static::assertSame(
            [null, true, false, []],
            [$defaults['cache'], $defaults['disable_on_csrf'], $defaults['options']['cache'], $defaults['routes']],
        );
    }

    #[Test]
    public function delegatesTheFormElementHelper(): void
    {
        static::assertSame(
            ['delegators' => [FormElement::class => [FormElementDisableCacheDelegator::class]]],
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
