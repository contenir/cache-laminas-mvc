<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc;

use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;

/**
 * Laminas MVC entry point.
 *
 * On bootstrap, fetches the CacheStrategy listener from the service manager
 * and calls its attach() — the listener walks its configured shared-event
 * identifiers/events (config[events][CacheStrategy::class]) and registers
 * itself with the shared event manager at the configured priorities.
 */
class Module
{
    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return (new ConfigProvider())();
    }

    public function onBootstrap(MvcEvent $event): void
    {
        $application = $event->getApplication();
        $listener    = $application->getServiceManager()->get(CacheStrategy::class);
        $this->attachListener($application->getEventManager(), $listener);
    }

    public function attachListener(EventManagerInterface $events, CacheStrategy $listener): void
    {
        $listener->attach($events);
    }
}