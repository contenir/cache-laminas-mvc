<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Unit;

use Contenir\Cache\Laminas\Mvc\ConfigProvider;
use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\Cache\Laminas\Mvc\Module;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Application;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('cache')]
final class ModuleTest extends TestCase
{
    #[Test]
    public function attachesTheListenerServiceToTheApplicationOnBootstrap(): void
    {
        $events   = $this->eventsExpectingAttachment();
        $listener = new CacheStrategy([Application::class => ['dispatch' => 1]]);
        $services = $this->createStub(ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([[CacheStrategy::class, $listener]]);
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getServiceManager')->willReturn($services);
        $application->method('getEventManager')->willReturn($events);
        $event = new MvcEvent();
        $event->setApplication($application);

        (new Module())->onBootstrap($event);
    }

    #[Test]
    public function attachesTheListenerToTheGivenEventManager(): void
    {
        (new Module())->attachListener(
            $this->eventsExpectingAttachment(),
            new CacheStrategy([Application::class => ['dispatch' => 1]]),
        );
    }

    #[Test]
    public function exposesTheConfigProviderConfiguration(): void
    {
        static::assertSame((new ConfigProvider())(), (new Module())->getConfig());
    }

    /**
     * Events whose shared manager expects the listener's dispatch and
     * disable-event attachments.
     */
    private function eventsExpectingAttachment(): EventManagerInterface
    {
        $shared = $this->createMock(SharedEventManagerInterface::class);
        $shared->expects($this->exactly(2))
            ->method('attach')
            ->with(Application::class, static::logicalOr('dispatch', CacheStrategy::EVENT_DISABLE));
        $events = $this->createStub(EventManagerInterface::class);
        $events->method('getSharedManager')->willReturn($shared);

        return $events;
    }
}
