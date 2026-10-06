# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.2.0] - 2026-10-05

### Changed

- Renamed from `contenir/cache-laminas-mvc` to
  `contenir/contenir-cache-laminas-mvc`. The package declares `replace` for
  the old name; require `contenir/contenir-cache-laminas-mvc` instead. See
  [UPGRADE-2.0.md](UPGRADE-2.0.md).
- The README and composer description no longer describe this package as an
  adapter for `contenir/cache`: it never required or used that package.

## [2.1.0] - 2026-10-05

### Added

- Infection mutation testing in CI, MSI 100%.
- `View\Helper\FormCsrfDisableCache` and `Factory\FormCsrfDisableCacheFactory`:
  the helper fires `CacheStrategy::EVENT_DISABLE`, then renders a `Csrf`
  element through `formhidden`. `ConfigProvider::getViewHelperConfig()`
  registers its factory.

### Changed

- `FormElementDisableCacheDelegator` no longer extends laminas-form's
  `@final` `FormElement` helper. It returns the helper the factory built,
  mapping the `Csrf` element class to `FormCsrfDisableCache`, instead of
  replacing it with a subclass. Behaviour is unchanged: rendering a `Csrf`
  element through `formElement()`, `formRow()`, `formCollection()` or
  `form()` still disables caching for the page, and other elements render
  as before. Type and class mappings configured on the factory-built helper
  are now kept.

## [2.0.0] - 2026-10-05

The major version marks the move to PHP 8.3+, the php-db QA toolchain shared
by all Contenir 2.x packages, and final classes. See
[UPGRADE-2.0.md](UPGRADE-2.0.md) for every break.

### Changed

- `LICENSE` names Contenir as the copyright holder, in line with the other
  Contenir packages, and uses the standard MIT wording.
- Requires PHP 8.3, 8.4 or 8.5, laminas-mvc 3.7+, laminas-cache 3.12+,
  laminas-eventmanager 3.13+, laminas-http 2.19+ and laminas-servicemanager
  3.22+ or 4. `laminas/laminas-stdlib` and `psr/container` are now declared
  directly.
- `Module` and `Listener\CacheStrategy` are `final`; the listener's protected
  properties and `makeCacheKey()` are private.
- `CacheStrategy::onDispatch()` returns `false|Response`;
  `CacheStrategy::detach()` drops its unused `$priority` parameter;
  `CacheStrategy::EVENT_DISABLE` is a typed constant.
- `CacheStrategy` takes the PHP SAPI as an optional second constructor
  argument (default `PHP_SAPI`) instead of reading it inside `onDispatch()`.
- A configured event priority of `0` is honoured; previously it fell back to
  the `attach()` priority. A priority that is not an integer falls back.
- `CacheStrategyFactory` skips malformed `events`, `options` and `routes`
  entries instead of passing them through.
- An event name with no `on*()` method makes `attach()` throw
  `InvalidArgumentException` instead of a `TypeError`.
- `FormElementDisableCacheDelegator` returns the original helper when the
  container has no MVC `Application`, and throws `UnexpectedValueException`
  when the delegated factory does not build a `FormElement`.

### Fixed

- A listener that handled a cacheable request kept its cache key, so a later
  uncacheable request (a POST, say) on the same instance was stored under the
  earlier page's key. The key is now reset on every dispatch.
- An authenticated identity without `getRoleId()` (such as a plain username)
  caused a fatal error on every request. Such requests are now not cached.
- `detach()` left the `EVENT_DISABLE` listener attached.
- Route patterns that PHP turns into integer keys (such as `'404'`) were
  renumbered by `array_merge()` in `setRoutes()` and never matched.
- A stored entry that is not a `Laminas\Http\Response`, or an application
  response that is not a `PhpEnvironment\Response`, caused a fatal error on a
  hit. Both are now treated as a miss.
- A numeric-string `ttl` option threw a `TypeError` under strict types.
- An event manager without a shared manager caused a fatal error in
  `attach()` and `detach()`.

### Added

- Continuous integration on PHP 8.3, 8.4 and 8.5 against lowest, locked and
  latest dependencies, with coverage reported to Codecov.
- Unit and integration test suites (there were none), with 100% line and
  branch coverage.

### Removed

- `squizlabs/php_codesniffer`, replaced by Mago via `php-db/phpdb-qa-tools`.

## [0.3.0]

- Defensive HTTP guards (GET/HEAD only, no `Range` or `Authorization`, 200
  only); cache key includes host and `Accept-Encoding`.

## [0.2.0]

- CSRF-aware caching: `CacheStrategy::EVENT_DISABLE` and the `FormElement`
  delegator.

## [0.1.4]

- Drop `SimpleCacheDecorator`; type the cache against `StorageInterface`.

## [0.1.3]

- Attach `CacheStrategy` automatically in `Module::onBootstrap()`.

## [0.1.2]

- Make the authentication service optional via a setter.

## [0.1.1]

- Add LICENSE and README.

## [0.1.0]

- Initial release: Laminas MVC page-cache listener.
