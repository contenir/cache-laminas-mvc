<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc;

use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Psr\Container\ContainerExceptionInterface;

/**
 * Laminas MVC entry point.
 *
 * On bootstrap, fetches the CacheStrategy listener from the service manager
 * and calls its attach() — the listener walks its configured shared-event
 * identifiers/events (config[events][CacheStrategy::class]) and registers
 * itself with the shared event manager at the configured priorities.
 *
 * @api
 */
final class Module
{
    public function attachListener(EventManagerInterface $events, CacheStrategy $listener): void
    {
        $listener->attach($events);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return (new ConfigProvider())();
    }

    /**
     * @throws ContainerExceptionInterface When the CacheStrategy listener cannot be built.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        $application = $event->getApplication();
        $listener    = $application->getServiceManager()->get(CacheStrategy::class);
        $this->attachListener($application->getEventManager(), $listener);
    }
}
