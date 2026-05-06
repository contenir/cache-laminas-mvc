<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Listener;

use Laminas\Authentication\AuthenticationService;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ListenerAggregateInterface;
use Laminas\Http\Header\HeaderInterface;
use Laminas\Http\Headers;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Mvc\MvcEvent;
use Laminas\Stdlib\RequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Page Caching Strategy Listener.
 *
 * Lifted from the Swinburne Site's Application\Listener\CacheStrategy.
 * The master enable/disable is driven by the standard pagecache config:
 * `pagecache.options.cache` set to `false` (per-environment, per-route, or
 * via admin writing pagecache.local.php) bypasses the cache entirely.
 *
 * Purging is *not* this listener's responsibility. Admin4 talks to the cache
 * storage backend directly (it has the same Site config, knows the adapter),
 * so there's no need for a signal-and-flush dance on the Site's request path.
 *
 * See the Swinburne original for the full caching-options reference. The
 * routes/options config shape is unchanged — existing Site configs port
 * across by moving them under the 'pagecache' key the ConfigProvider declares.
 */
class CacheStrategy implements ListenerAggregateInterface
{
    protected CacheInterface $cache;
    protected ?string $key = null;
    protected ?int $ttl = null;
    protected array $activeOptions = [];
    protected array $options = [
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
    protected array $routes = [];

    public function __construct(
        protected AuthenticationService $authService,
        protected array $configuration,
    ) {
    }

    public function setCache(CacheInterface $cache): static
    {
        $this->cache = $cache;

        return $this;
    }

    public function setOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function setRoutes(array $routes): static
    {
        $this->routes = array_merge($this->routes, $routes);

        return $this;
    }

    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $sharedManager = $events->getSharedManager();

        foreach ($this->configuration as $identifier => $settings) {
            foreach ($settings as $event => $configPriority) {
                $sharedManager->attach(
                    $identifier,
                    $event,
                    [$this, 'on' . ucwords((string) $event)],
                    $configPriority ?: $priority
                );
            }
        }
    }

    public function detach(EventManagerInterface $events, int $priority = 1): void
    {
        $sharedManager = $events->getSharedManager();

        foreach ($this->configuration as $identifier => $settings) {
            foreach ($settings as $event => $configPriority) {
                $sharedManager->detach(
                    [$this, 'on' . ucwords((string) $event)],
                    $identifier,
                    $event,
                );
            }
        }
    }

    public function onDispatch(MvcEvent $event): bool|Response
    {
        if (php_sapi_name() == 'cli') {
            return false;
        }

        $request = $event->getRequest();
        $path    = $request->getUri()->getPath();

        $lastMatchingRegexp = null;
        foreach ($this->routes ?? [] as $regexp => $conf) {
            if (preg_match("`$regexp`", (string) $path)) {
                $lastMatchingRegexp = $regexp;
            }
        }

        $this->activeOptions = $this->options;

        if ($lastMatchingRegexp !== null) {
            $conf = $this->routes[$lastMatchingRegexp];
            foreach ($conf as $key => $value) {
                $this->activeOptions[$key] = $value;
            }
        }

        if (! ($this->activeOptions['cache'] ?? null)) {
            return false;
        }

        $this->key = (string) $this->makeCacheKey($request);
        if ($this->key === '' || $this->key === '0') {
            return false;
        }

        $this->ttl = $this->activeOptions['ttl'];

        if ($this->cache->has($this->key)) {
            $response = $event->getApplication()->getResponse();
            $hit      = $this->cache->get($this->key);
            $headers  = $hit->getHeaders();

            self::sanitizeHitHeaders($headers);

            // Null the key so onFinish doesn't re-store a HIT response
            // (and overwrite our X-PK-Cache: HIT marker with MISS).
            $this->key = null;

            return $response
                ->setHeaders($headers)
                ->setContent($hit->getBody());
        }

        return false;
    }

    /**
     * Make an id depending on REQUEST_URI and superglobal arrays (depending on options)
     *
     * @return string|bool a cache id (string), false if the cache should have not to be used
     */
    protected function makeCacheKey(RequestInterface $request): string|bool
    {
        $value = $request->getUri()->getPath();

        foreach (['query', 'post', 'files', 'session', 'cookie'] as $variable) {
            switch ($variable) {
                case 'session':
                    $identity = $this->authService->getIdentity();
                    if ($identity) {
                        $value .= $identity->getRoleId();
                    }
                    break;

                default:
                    $method = 'get' . ucwords($variable);
                    $vars   = $request->{$method}();
                    if (! $vars) {
                        continue 2;
                    }
                    $vars = $vars->getArrayCopy();
                    if (count($vars) > 0) {
                        if (! $this->activeOptions["cache_with_$variable"]) {
                            return false;
                        }
                        if ($this->activeOptions["make_id_with_$variable"]) {
                            $value .= md5(serialize($vars));
                        }
                    }
                    break;
            }
        }

        return md5((string) $value);
    }

    public function onFinish(MvcEvent $event): void
    {
        if (! $this->key) {
            return;
        }

        $response = $event->getResponse();
        $headers  = $response->getHeaders();

        self::sanitizeStoreHeaders($headers);

        $this->cache->set($this->key, $response, $this->ttl);
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
            'Vary'          => 'Accept-Encoding, Cookie',
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
            'Vary'       => 'Accept-Encoding, Cookie',
            'X-PK-Cache' => 'MISS',
        ]);
    }

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
     * No-op once headers have already been sent (e.g. unit-test contexts).
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

    /**
     * Remove every instance of a named header from the collection. Handles
     * both single-header (HeaderInterface) and multi-value (ArrayObject of
     * HeaderInterface, e.g. Set-Cookie) results from Headers::get().
     */
    private static function stripHeader(Headers $headers, string $name): void
    {
        $existing = $headers->get($name);
        if ($existing === false) {
            return;
        }

        if ($existing instanceof HeaderInterface) {
            $headers->removeHeader($existing);
            return;
        }

        if (is_iterable($existing)) {
            foreach (iterator_to_array($existing, false) as $header) {
                if ($header instanceof HeaderInterface) {
                    $headers->removeHeader($header);
                }
            }
        }
    }
}
