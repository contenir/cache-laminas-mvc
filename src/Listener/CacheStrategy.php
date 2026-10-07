<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Listener;

use ArrayIterator;
use Closure;
use InvalidArgumentException;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Exception\ExceptionInterface as CacheException;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ListenerAggregateInterface;
use Laminas\Http\Header\HeaderInterface;
use Laminas\Http\Headers;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\MvcEvent;
use Override;
use Traversable;

use function array_keys;
use function array_replace;
use function header_remove;
use function headers_sent;
use function in_array;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function iterator_to_array;
use function md5;
use function method_exists;
use function preg_match;
use function serialize;
use function sprintf;
use function ucwords;

use const PHP_SAPI;

/**
 * Page Caching Strategy Listener.
 *
 * The master enable/disable is driven by the standard pagecache config:
 * `pagecache.options.cache` set to `false` (per-environment, per-route, or
 * via an admin tool writing pagecache.local.php) bypasses the cache
 * entirely.
 *
 * Purging is *not* this listener's responsibility. The consuming admin
 * tool talks to the cache storage backend directly (same Site config,
 * known adapter), so there's no signal-and-flush dance on the request
 * path.
 *
 * The `routes`/`options` config shape under the `pagecache` key follows
 * the long-standing `cache_with_*` / `make_id_with_*` flag layout so
 * existing Site configs port over unchanged.
 *
 * @api
 */
final class CacheStrategy implements ListenerAggregateInterface
{
    public const string EVENT_DISABLE = 'pagecache.disable';

    private const int DISABLE_PRIORITY = 100;

    private const string VARY = 'Accept-Encoding, Cookie';

    private ?StorageInterface $cache = null;

    private ?string $key = null;

    private ?int $ttl = null;

    private bool $disabled = false;

    /**
     * @var array<string, mixed>
     */
    private array $activeOptions = [];

    /**
     * @var array<string, mixed>
     */
    private array $options = [
        'cache_with_query'     => false,
        'cache_with_post'      => false,
        'cache_with_session'   => false,
        'cache_with_files'     => false,
        'cache_with_cookie'    => false,
        'make_id_with_query'   => false,
        'make_id_with_post'    => false,
        'make_id_with_session' => false,
        'make_id_with_files'   => false,
        'make_id_with_cookie'  => false,
        'cache'                => false,
        'ttl'                  => null,
        'priority'             => null,
    ];

    /**
     * @var array<array-key, array<string, mixed>>
     */
    private array $routes = [];

    private ?AuthenticationServiceInterface $authService = null;

    /**
     * @var array<string, Closure>
     */
    private array $listeners = [];

    /**
     * @param array<string, array<string, mixed>> $configuration Shared-event
     *     identifier => [event name => priority]. A priority that is not an
     *     integer falls back to the priority passed to attach(). Each event
     *     name maps to the listener method 'on' . ucwords($event).
     * @param string $sapi The PHP SAPI the request runs under. Under `cli`
     *     there is no page to cache (console tools, and functional tests of
     *     the application), so the listener stays out of the way.
     */
    public function __construct(
        private array $configuration,
        private readonly string $sapi = PHP_SAPI,
    ) {}

    /**
     * Clear PHP's pending response headers for a given name.
     *
     * Called alongside Laminas-collection sanitisation because session_start()
     * and other PHP modules emit headers (Pragma, Expires, Cache-Control,
     * Set-Cookie) directly via the C-level header() function — those don't
     * appear in Laminas's Response\Headers collection but are queued in PHP's
     * own output buffer until headers_sent(). This drops them before
     * Application::send() flushes the response.
     *
     * No-op once headers have already been sent, when header_remove() could
     * only warn.
     */
    private static function clearEmittedHeaders(string ...$names): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($names as $name) {
            header_remove($name);
        }
    }

    private static function header(mixed $header): ?HeaderInterface
    {
        return $header instanceof HeaderInterface ? $header : null;
    }

    private static function isTruthy(mixed $value): bool
    {
        return is_scalar($value) && (bool) $value;
    }

    private static function priorityOr(mixed $configured, mixed $default): int
    {
        if (is_int($configured)) {
            return $configured;
        }

        return is_int($default) ? $default : 1;
    }

    private static function responseFrom(mixed $item): ?HttpResponse
    {
        return $item instanceof HttpResponse ? $item : null;
    }

    private static function roleOf(mixed $identity): ?string
    {
        if (null === $identity) {
            return '';
        }

        if (! is_object($identity) || ! method_exists($identity, 'getRoleId')) {
            return null;
        }

        return self::scalarString($identity->getRoleId());
    }

    /**
     * Sanitize headers being served from cache (HIT path).
     *
     * Strips:
     * - `Set-Cookie` — must never replay across clients on a future HIT.
     * - `Pragma`, `Expires` — typical session_start() side effects that
     *   would tell browsers the response is ancient and shouldn't be reused,
     *   conflicting with the listener's intent to serve a cached copy.
     *
     * Replaces (so multiple stored entries don't accumulate):
     * - `Cache-Control: no-cache` — browser must revalidate.
     * - `Vary: Accept-Encoding, Cookie` — downstream caches mustn't hand
     *   user A's response to user B; the listener already keys per-cookie
     *   when `cache_with_cookie`/`cache_with_session` is on, but downstream
     *   caches need the same hint.
     * - `X-PK-Cache: HIT` — diagnostic marker.
     */
    private static function sanitizeHitHeaders(Headers $headers): void
    {
        self::stripHeader($headers, 'Set-Cookie');
        self::stripHeader($headers, 'Pragma');
        self::stripHeader($headers, 'Expires');

        self::stripHeader($headers, 'Cache-Control');
        self::stripHeader($headers, 'Vary');
        self::stripHeader($headers, 'X-PK-Cache');

        $headers->addHeaders([
            'Cache-Control' => 'no-cache',
            'Vary'          => self::VARY,
            'X-PK-Cache'    => 'HIT',
        ]);

        // session_start()'s session_cache_limiter has queued Pragma/Expires
        // directly into PHP's pending headers — those don't live in Laminas's
        // collection so the strips above can't see them. Clear from PHP's
        // queue. Cache-Control gets replaced naturally because Laminas's
        // send() emits header(..., replace=true) for the override above.
        self::clearEmittedHeaders('Pragma', 'Expires');
    }

    /**
     * Sanitize headers on the response about to be stored (MISS path).
     *
     * Strips `Set-Cookie` *before* storing so the cache itself never holds
     * a session cookie that could replay to a different client.
     *
     * Replaces `Vary` and adds `X-PK-Cache: MISS` so the current client sees
     * a marker and the stored copy carries `Vary` for any downstream cache
     * (the HIT path overrides X-PK-Cache to HIT on next request).
     */
    private static function sanitizeStoreHeaders(Headers $headers): void
    {
        self::stripHeader($headers, 'Set-Cookie');

        self::stripHeader($headers, 'Vary');
        self::stripHeader($headers, 'X-PK-Cache');

        $headers->addHeaders([
            'Vary'       => self::VARY,
            'X-PK-Cache' => 'MISS',
        ]);
    }

    private static function scalarString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Remove every instance of a named header from the collection. Handles
     * both single-header (HeaderInterface) and multi-value (ArrayIterator of
     * HeaderInterface, e.g. Set-Cookie) results from Headers::get().
     */
    private static function stripHeader(Headers $headers, string $name): void
    {
        $existing = $headers->get($name);
        if ($existing instanceof ArrayIterator) {
            /** @var list<HeaderInterface> $all */
            $all = iterator_to_array($existing, preserve_keys: false);
            foreach ($all as $header) {
                $headers->removeHeader($header);
            }

            return;
        }

        $single = self::header($existing);
        if (null !== $single) {
            $headers->removeHeader($single);
        }
    }

    private static function ttlFrom(mixed $ttl): ?int
    {
        return is_numeric($ttl) ? (int) $ttl : null;
    }

    /**
     * The entries of a request variable set (query, post, files) or of the
     * Cookie header; empty when the request has none.
     *
     * @return array<array-key, mixed>
     */
    private static function values(mixed $variables): array
    {
        return $variables instanceof Traversable ? iterator_to_array($variables) : [];
    }

    /**
     * @param int $priority Used for configured events whose priority is not an integer.
     */
    #[Override]
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $sharedManager = $events->getSharedManager();
        if (null === $sharedManager) {
            return;
        }

        foreach ($this->configuration as $identifier => $settings) {
            foreach (array_keys($settings) as $event) {
                $sharedManager->attach(
                    $identifier,
                    $event,
                    $this->listenerFor($event),
                    self::priorityOr($settings[$event] ?? null, $priority),
                );
            }

            $sharedManager->attach($identifier, self::EVENT_DISABLE, [$this, 'disable'], self::DISABLE_PRIORITY);
        }
    }

    #[Override]
    public function detach(EventManagerInterface $events): void
    {
        $sharedManager = $events->getSharedManager();
        if (null === $sharedManager) {
            return;
        }

        foreach ($this->configuration as $identifier => $settings) {
            foreach (array_keys($settings) as $event) {
                $sharedManager->detach($this->listenerFor($event), $identifier, $event);
            }

            $sharedManager->detach([$this, 'disable'], $identifier, self::EVENT_DISABLE);
        }
    }

    /**
     * Mark the current request as uncacheable.
     *
     * Once flipped, onFinish will not store the response and any subsequent
     * shared listener attached to EVENT_DISABLE on this same request is a
     * no-op. Reset between requests by re-resolving the listener (singleton
     * scope means the consuming app should call this only on requests that
     * really shouldn't cache, e.g. CSRF-bearing pages).
     */
    public function disable(): void
    {
        $this->disabled = true;
    }

    /**
     * @throws CacheException When the cache storage cannot be read.
     */
    public function onDispatch(MvcEvent $event): false|Response
    {
        $this->key = null;

        $cache = $this->cache;
        if ($this->disabled || null === $cache || 'cli' === $this->sapi) {
            return false;
        }

        $request = $event->getRequest();

        if (! $request instanceof HttpRequest) {
            return false;
        }

        if (! in_array($request->getMethod(), [HttpRequest::METHOD_GET, HttpRequest::METHOD_HEAD], strict: true)) {
            return false;
        }

        /** @var Headers $headers */
        $headers = $request->getHeaders();
        if ($headers->has('Range') || $headers->has('Authorization')) {
            return false;
        }

        $this->activeOptions = $this->resolveOptions((string) $request->getUri()->getPath());

        if (! $this->isOptionOn('cache')) {
            return false;
        }

        $key = $this->makeCacheKey($request);
        if (false === $key) {
            return false;
        }

        $this->ttl = self::ttlFrom($this->activeOptions['ttl'] ?? null);
        $this->key = $key;

        if (! $cache->hasItem($key)) {
            return false;
        }

        $response = $event->getApplication()->getResponse();
        $hit      = self::responseFrom($cache->getItem($key));

        if (! $response instanceof Response || null === $hit) {
            return false;
        }

        $headers = $hit->getHeaders();

        self::sanitizeHitHeaders($headers);

        // Null the key so onFinish doesn't re-store a HIT response
        // (and overwrite our X-PK-Cache: HIT marker with MISS).
        $this->key = null;

        $response->setHeaders($headers)->setContent($hit->getBody());

        return $response;
    }

    /**
     * @throws CacheException When the cache storage cannot be written.
     */
    public function onFinish(MvcEvent $event): void
    {
        $cache = $this->cache;
        if ($this->disabled || null === $this->key || null === $cache) {
            return;
        }

        $response = $event->getResponse();

        if (! $response instanceof Response || 200 !== $response->getStatusCode()) {
            return;
        }

        self::sanitizeStoreHeaders($response->getHeaders());

        $options     = $cache->getOptions();
        $previousTtl = $options->getTtl();
        if (null !== $this->ttl) {
            $options->setTtl($this->ttl);
        }

        try {
            $cache->setItem($this->key, $response);
        } finally {
            $options->setTtl($previousTtl);
        }
    }

    public function setAuthenticationService(AuthenticationServiceInterface $authService): static
    {
        $this->authService = $authService;

        return $this;
    }

    public function setCache(StorageInterface $cache): static
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): static
    {
        $this->options = array_replace($this->options, $options);

        return $this;
    }

    /**
     * @param array<array-key, array<string, mixed>> $routes
     */
    public function setRoutes(array $routes): static
    {
        $this->routes = array_replace($this->routes, $routes);

        return $this;
    }

    private function isOptionOn(string $name): bool
    {
        return self::isTruthy($this->activeOptions[$name] ?? null);
    }

    /**
     * The listener method for a configured event, as the same Closure every
     * time so detach() can find what attach() registered.
     *
     * @throws InvalidArgumentException When the class has no matching on* method.
     */
    private function listenerFor(string $event): Closure
    {
        $method = 'on' . ucwords($event);
        if (! method_exists($this, $method)) {
            throw new InvalidArgumentException(sprintf(
                'No listener method %s::%s() for the configured event "%s".',
                self::class,
                $method,
                $event,
            ));
        }

        /** @var callable(MvcEvent): mixed $listener */
        $listener = [$this, $method];

        return $this->listeners[$method] ??= Closure::fromCallable($listener);
    }

    /**
     * Build the cache key for the current request.
     *
     * Always includes host + path + Accept-Encoding (so multi-host
     * deployments don't cross-pollute and gzipped responses don't leak
     * to clients that didn't ask for them). Also includes the role
     * suffix for an authenticated identity if an AuthenticationService
     * is wired, and md5(serialize) of any superglobal whose
     * `make_id_with_*` flag is true and whose `cache_with_*` flag
     * permits caching at all.
     *
     * @return string|false a cache id, or false if the cache should not be used
     */
    private function makeCacheKey(HttpRequest $request): string|false
    {
        $uri   = $request->getUri();
        $value = (string) $uri->getHost() . (string) $uri->getPath();

        $acceptEncoding = self::header($request->getHeader('Accept-Encoding'));
        if (null !== $acceptEncoding) {
            $value .= "|enc:{$acceptEncoding->getFieldValue()}";
        }

        foreach (['query', 'post', 'files', 'session', 'cookie'] as $variable) {
            $part = 'session' === $variable ? $this->roleKeyPart() : $this->variableKeyPart($request, $variable);
            if (null === $part) {
                return false;
            }

            $value .= $part;
        }

        return md5($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOptions(string $path): array
    {
        $lastMatchingRegexp = null;
        foreach (array_keys($this->routes) as $regexp) {
            if (1 !== preg_match("`{$regexp}`", $path)) {
                continue;
            }

            $lastMatchingRegexp = $regexp;
        }

        if (null === $lastMatchingRegexp) {
            return $this->options;
        }

        return array_replace($this->options, $this->routes[$lastMatchingRegexp] ?? []);
    }

    /**
     * The role of the authenticated identity, so two roles never share a
     * cached page. Null when there is an identity whose role cannot be told,
     * since its page may be personal and must not be cached.
     */
    private function roleKeyPart(): ?string
    {
        return self::roleOf($this->authService?->getIdentity());
    }

    /**
     * The key contribution of one request variable set. Null when the set is
     * present but its `cache_with_*` option forbids caching.
     */
    private function variableKeyPart(HttpRequest $request, string $variable): ?string
    {
        $vars = match ($variable) {
            'query' => self::values($request->getQuery()),
            'post'  => self::values($request->getPost()),
            'files' => self::values($request->getFiles()),
            default => self::values($request->getCookie()),
        };

        if ([] === $vars) {
            return '';
        }

        if (! $this->isOptionOn("cache_with_{$variable}")) {
            return null;
        }

        return $this->isOptionOn("make_id_with_{$variable}") ? md5(serialize($vars)) : '';
    }
}
