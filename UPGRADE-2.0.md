# Upgrading from 0.x to 2.0

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.1 | 8.3, 8.4 or 8.5 |
| laminas/laminas-mvc | ^3.4 | ^3.7 |
| laminas/laminas-cache | ^3.0 \|\| ^4.0 | ^3.12 \|\| ^4.0 |
| laminas/laminas-eventmanager | ^3.0 | ^3.13 |
| laminas/laminas-http | ^2.0 | ^2.19 |
| laminas/laminas-servicemanager | ^3.0 \|\| ^4.0 | ^3.22 \|\| ^4.0 |

```bash
composer require contenir/cache-laminas-mvc:^2.0
```

Sites that only configure the module (`pagecache` and `events` config) need no
code changes. The breaks below affect code that extends or calls the classes
directly. Projects that must stay on PHP 8.1 or 8.2 can keep using `^0.3`,
maintained on the `0.x` branch.

## `Module` and `CacheStrategy` are final

Before:

```php
class SiteCacheStrategy extends \Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy
{
    protected function makeCacheKey(RequestInterface $request): string|bool { /* ... */ }
}
```

After: neither class can be extended. `CacheStrategy`'s protected properties
(`$cache`, `$key`, `$ttl`, `$disabled`, `$activeOptions`, `$options`,
`$routes`, `$authService`, `$configuration`) and `makeCacheKey()` are now
private. Configure behaviour through `setOptions()`, `setRoutes()`,
`setAuthenticationService()` and the `pagecache` config instead; if you need a
hook that is missing, open an issue.

## `CacheStrategy` signatures

| Member | 0.x | 2.0 |
| --- | --- | --- |
| `onDispatch()` | `bool\|Response` | `false\|Response` |
| `detach()` | `detach(EventManagerInterface $events, int $priority = 1)` | `detach(EventManagerInterface $events)` |
| `EVENT_DISABLE` | `const EVENT_DISABLE` | `const string EVENT_DISABLE` |
| `__construct()` | `(array $configuration)` | `(array $configuration, string $sapi = PHP_SAPI)` |

Callers passing a second argument to `detach()` keep working (PHP ignores the
extra argument). The new `$sapi` argument is optional.

## Behaviour changes

### Event priority `0` is honoured

```php
'events' => [CacheStrategy::class => [Application::class => ['dispatch' => 0]]],
```

Before, `0` was replaced by the `attach()` priority (`1`). After, the
listener attaches at `0`. A priority that is not an integer (for example
`null` or `'5'`) falls back to the `attach()` priority.

### Unknown events throw `InvalidArgumentException`

Before, configuring an event with no matching `on*()` method, such as
`'route' => 1`, failed with a `TypeError` from the shared event manager.
After, `attach()` throws `InvalidArgumentException` naming the missing method.

### Identities without a role are not cached

Before, an authenticated identity without `getRoleId()` (for example the plain
username string `AuthenticationService` stores by default) caused a fatal
error. After, such requests, and identities whose role is not a scalar, are
simply not cached.

### Malformed configuration is skipped

`CacheStrategyFactory` ignores event identifiers or names that are not
strings, options without a string name, and route overrides that are not
arrays. Before, they reached the listener and failed later.

### The CSRF delegator degrades instead of failing

When the container has no MVC `Application` service, the delegator returns
the original `FormElement` helper (before: an error on helper creation). When
the delegated factory does not build a `FormElement`, it throws
`UnexpectedValueException` (before: a `TypeError`).
