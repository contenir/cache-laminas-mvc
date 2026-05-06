<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc;

/**
 * Laminas MVC entry point.
 *
 * The CacheStrategy listener is attached via shared-event-manager hooks at
 * construction time (it implements ListenerAggregateInterface and reads its
 * own attach configuration). This Module is just the standard config wiring;
 * the consuming Site is expected to register the listener as a service and
 * pull it once during bootstrap (see README).
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
}
