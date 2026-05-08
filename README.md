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

### CSRF-aware caching

A page that renders a `Laminas\Form\Element\Csrf` token is per-user and
must not be cached — the token is bound to the user's session, and a
cached HTML page would replay one user's token to the next.

When `laminas/laminas-form` is installed, the package's
`ConfigProvider` registers a delegator on the `FormElement` view
helper that fires `CacheStrategy::EVENT_DISABLE` whenever a `Csrf`
element is rendered. The listener attaches to that event on the same
identifier(s) it uses for `dispatch`/`finish`; on receipt it flips an
internal `disabled` flag, and `onFinish` skips storage. Pages with
forms render normally; only the *caching* of those pages is suppressed.

To opt out:

```php
// config/autoload/pagecache.local.php
return [
    'pagecache' => [
        'disable_on_csrf' => false,
    ],
];
```

When `disable_on_csrf` is false the delegator returns the original
`FormElement` helper untouched (no overhead, no event firing).

For non-Laminas-form CSRF rendering, or any other reason a page must
opt out at runtime, fire the event yourself from anywhere in the
request lifecycle:

```php
$em->trigger(\Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy::EVENT_DISABLE);
```

…or grab the listener service and call `disable()` directly.

## How it works

`Listener\CacheStrategy` typically attaches to `MvcEvent::EVENT_DISPATCH`
(early) and `EVENT_FINISH` (late). On the inbound pass it builds the
cache key from the configured request signals, returns a stored response
when one exists, and short-circuits dispatch. On the outbound pass it
stores the final response when the active options say to cache it.

`pagecache.options.cache = false` disables the listener entirely for
the request — useful as an admin-controlled kill switch and as a
per-route override for endpoints that must never be cached.

`CacheStrategy::EVENT_DISABLE` (`'pagecache.disable'`) lets per-render
opt-out signals reach the listener: anything in the request that knows
the response is not safe to cache (CSRF tokens, flash messages,
authenticated banners) can fire the event and the listener will
short-circuit `onFinish` for that request.

## What's in the cache key

The key is `md5()` of:

1. **Host** — `$request->getUri()->getHost()`. Multi-host deployments
   never cross-pollute.
2. **Path** — `$request->getUri()->getPath()`.
3. **`Accept-Encoding`** request header value — so a gzipped response
   stored against a `gzip`-accepting client is never served to a client
   that didn't advertise gzip support.
4. **Authenticated role suffix** — `$identity->getRoleId()` when an
   `AuthenticationServiceInterface` service is registered and an
   identity is present. No service registered ⇒ this branch no-ops
   (fine for purely-public sites).
5. **Superglobal hashes** — `md5(serialize($vars))` for each of
   `query`, `post`, `files`, `cookie` whose `make_id_with_*` flag is
   true. Whose presence with `cache_with_*` set false short-circuits
   caching entirely for the request.

Anything *not* in this list — `User-Agent`, `Referer`, custom `X-*`
headers, third-party tracking cookies your app never reads — is
**invisible to the cache by design**. The cache assumes the response
is a pure function of the inputs above. If a controller varies its
response on something outside that set (e.g. UA-sniffing for mobile
markup) without keying on it, that's a poisoning bug in the
controller, not the cache.

## What's never cached

The listener short-circuits in `onDispatch` for any of:

- non-`GET`/`HEAD` request methods (so file-upload `POST`s don't even
  buffer through the cache layer)
- `Range:` request header present (don't cache 206 partial responses
  as if they were full)
- `Authorization:` request header present (per-user credentials ⇒
  per-user response)

…and in `onFinish` for any response with a status code other than
`200 OK` (catches `304`, `301`/`302` redirects, `404`/`5xx` errors).

These are unconditional — there is no config flag to turn them off.
If you have a route that genuinely needs to cache a non-200 response,
that's a different design problem than this listener is built for.

## Purging

Purging is *not* this listener's responsibility. Admin tooling that
wants to clear cached pages (or specific keys) talks to the same cache
storage backend directly — the Site config tells it which adapter the
listener is wrapping.
