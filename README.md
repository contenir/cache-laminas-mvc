# contenir/cache-laminas-mvc

Laminas MVC adapter for [`contenir/cache`](https://github.com/contenir/cache).

A page-cache `MvcEvent` listener with the legacy `cache_with_*` /
`make_id_with_*` shape preserved, driven by the standard `pagecache`
config key. Admin-side toggles (`pagecache.options.cache`,
per-route overrides) ride in via the merged Laminas/Mezzio config — no
in-band purge signal on the request path.

## Install

```bash
composer require contenir/cache-laminas-mvc
```

The Module is auto-registered by `laminas/laminas-component-installer`.

## Configure

Point the listener at a Laminas cache storage service ID, declare the
event-manager identifiers/events to attach to, and (optionally) defaults
plus per-route overrides:

```php
// config/autoload/pagecache.global.php

use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;

return [
    'pagecache' => [
        // Service ID resolving to a Laminas\Cache\Storage\StorageInterface.
        'cache'   => 'cache.pagecache',

        // Default per-request options. The `cache` flag is the master
        // enable switch — admin's pagecache.local.php overrides it
        // without touching siblings.
        'options' => [
            'cache'              => true,
            'cache_with_query'   => true,
            'cache_with_session' => false,
            'ttl'                => 600,
        ],

        // Optional regex => options-overrides.
        'routes'  => [
            '/api.*' => ['cache' => false],
        ],
    ],

    // Shared-event-manager attachments. The keys are SharedEventManager
    // identifiers (typically Application::class); the values are
    // event-name => priority pairs. Event names map to listener methods
    // via `'on' . ucwords($event)` — so 'dispatch' → onDispatch,
    // 'finish' → onFinish.
    'events' => [
        CacheStrategy::class => [
            \Laminas\Mvc\Application::class => [
                'dispatch' => -100,
                'finish'   =>  100,
            ],
        ],
    ],
];
```

A separate `pagecache.local.php` (written by the admin) is merged on top
in the standard Laminas config-aggregator order, so the operator's
defaults are preserved when the admin flips the master toggle.

The Module attaches the listener for you on bootstrap — there's nothing
to wire in your Site's own `Application\Module`.

### Optional: auth-aware cache keys

If the Site has authenticated frontend users and a cached page should
not be shared between roles, register a service for
`Laminas\Authentication\AuthenticationServiceInterface`. The factory
will pull it via `setAuthenticationService()` and the role identifier
will be mixed into the cache key. Without it, the role-suffix branch
silently no-ops — fine for purely-public sites.

## How it works

`Listener\CacheStrategy` typically attaches to `MvcEvent::EVENT_DISPATCH`
(early) and `EVENT_FINISH` (late). On the inbound pass it builds the
cache key from the configured request signals, returns a stored response
when one exists, and short-circuits dispatch. On the outbound pass it
stores the final response when the active options say to cache it.

`pagecache.options.cache = false` disables the listener entirely for
the request — useful as an admin-controlled kill switch and as a
per-route override for endpoints that must never be cached.

## Purging

Purging is *not* this listener's responsibility. Admin tooling that
wants to clear cached pages (or specific keys) talks to the same cache
storage backend directly — the Site config tells it which adapter the
listener is wrapping.
