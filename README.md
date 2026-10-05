# contenir/cache-laminas-mvc

[![Continuous Integration](https://github.com/contenir/cache-laminas-mvc/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/cache-laminas-mvc/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/cache-laminas-mvc/graph/badge.svg)](https://codecov.io/gh/contenir/cache-laminas-mvc)

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

Requires PHP 8.3, 8.4 or 8.5, laminas-mvc 3.7+, laminas-cache 3.12+ and
laminas-servicemanager 3.22+ or 4. The 0.x releases, which support PHP 8.1,
remain available from the `0.x` branch and `v0.*` tags; see
[UPGRADE-2.0.md](UPGRADE-2.0.md).

The Module is auto-registered by `laminas/laminas-component-installer`.

## Public API

| Class | Purpose |
| --- | --- |
| `Module` | Laminas module: `getConfig()` returns the `ConfigProvider` config; `onBootstrap()` attaches the listener. `attachListener($events, $listener)` does the attaching. |
| `ConfigProvider` | `__invoke()`, `getDependencies()`, `getPageCacheDefaults()`, `getViewHelperConfig()`. |
| `Factory\CacheStrategyFactory` | Builds the listener from `config[events][CacheStrategy::class]` and `config[pagecache]`. |
| `Listener\CacheStrategy` | The listener: `attach()`, `detach()`, `onDispatch()`, `onFinish()`, `disable()`, `setCache()`, `setOptions()`, `setRoutes()`, `setAuthenticationService()`, and the `EVENT_DISABLE` constant. |
| `View\Helper\Delegator\FormElementDisableCacheDelegator` | Fires `EVENT_DISABLE` when a `Csrf` element is rendered. |

All classes are `final`.

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

The factory throws a `RuntimeException` when `pagecache.cache` is not a
non-empty service ID, or when that service is not a
`Laminas\Cache\Storage\StorageInterface`. Malformed entries are skipped:
event identifiers and event names that are not strings, options without a
string name, and route overrides that are not arrays. An event priority that
is not an integer falls back to the `attach()` priority. An event name with no
matching `on*()` listener method (only `dispatch` and `finish` exist) throws
an `InvalidArgumentException` from `attach()`.

`options.ttl` may be an integer or numeric string; anything else leaves the
storage's own TTL in place.

A separate `pagecache.local.php` (written by the admin) is merged on top
in the standard Laminas config-aggregator order, so the operator's
defaults are preserved when the admin flips the master toggle.

The Module attaches the listener for you on bootstrap — there's nothing
to wire in your Site's own `Application\Module`.

### Console and tests

The listener never caches under the `cli` SAPI, so console tools and your
application's functional tests always see live responses. The SAPI is the
listener's second constructor argument and defaults to `PHP_SAPI`; build it
yourself with another value only when you need to exercise caching from the
CLI:

```php
$listener = (new CacheStrategy($events, sapi: 'fpm-fcgi'))->setCache($storage);
```

### Optional: auth-aware cache keys

If the Site has authenticated frontend users and a cached page should
not be shared between roles, register a service for
`Laminas\Authentication\AuthenticationServiceInterface`. The factory
will pull it via `setAuthenticationService()` and the role identifier
will be mixed into the cache key. Without it, the role-suffix branch
silently no-ops — fine for purely-public sites. An identity that has no
`getRoleId()` method (for example a plain username string), or whose role is
not a scalar, makes the page uncacheable for that request, since its content
may be personal.

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

When `disable_on_csrf` is false, or the container has no MVC `Application`
service, the delegator returns the original `FormElement` helper untouched
(no overhead, no event firing).

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
   (fine for purely-public sites). An identity without a usable role
   ⇒ the request is not cached.
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

- a disabled listener, a missing cache storage, or the `cli` SAPI

A stored entry that is not a `Laminas\Http\Response`, or an application
response that is not a `Laminas\Http\PhpEnvironment\Response`, is treated as
a miss, and the fresh response replaces the entry.

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

## Development

The QA toolchain is [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: collaborators doubled, no I/O
composer test-integration  # integration suite: real event manager, memory cache, service and helper managers
composer test-coverage     # both suites, clover.xml for Codecov
composer mutation-test     # Infection mutation testing over both suites
```

## License

MIT. See [LICENSE](LICENSE).
