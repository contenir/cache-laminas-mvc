<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Trait;

use Contenir\PageCache\CacheControl;
use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\PageCache\Laminas\Mvc\Module;
use Contenir\PageCache\Repository\InMemoryRepository;
use Laminas\Cache\Storage\Adapter\Memory;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Mvc\Application;
use Laminas\Mvc\MvcEvent;

/**
 * A real event manager and in-memory cache storage with the listener
 * attached the way Module::onBootstrap() attaches it.
 */
trait CachingApplicationTrait
{
    private EventManager $events;

    private Memory $storage;

    private CacheStrategy $listener;

    protected function setUpCachingApplication(): void
    {
        $this->events   = new EventManager(new SharedEventManager(), [Application::class]);
        $this->storage  = new Memory();
        $this->listener = (new CacheStrategy([
            Application::class => [MvcEvent::EVENT_DISPATCH => -100, MvcEvent::EVENT_FINISH => 100],
        ], sapi: 'fpm-fcgi'))->setCache($this->storage)
            ->setRepository(new InMemoryRepository(CacheControl::enabled()));

        (new Module())->attachListener($this->events, $this->listener);
    }

    private function dispatch(MvcEvent $event): mixed
    {
        $event->setName(MvcEvent::EVENT_DISPATCH);

        return $this->events
            ->triggerEventUntil(static fn(mixed $result): bool => $result instanceof Response, $event)
            ->last();
    }

    private function finish(Response $response): void
    {
        $event = new MvcEvent(MvcEvent::EVENT_FINISH);
        $event->setResponse($response);
        $this->events->triggerEvent($event);
    }
}
