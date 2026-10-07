<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Unit\Listener;

use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\PageCache\Laminas\Mvc\Tests\TestAsset\Identity\RoleIdentity;
use Contenir\PageCache\Laminas\Mvc\Tests\Trait\MvcEventTrait;
use InvalidArgumentException;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Cache\Exception\RuntimeException as CacheRuntimeException;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Http\Header\SetCookie;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Application;
use Laminas\Stdlib\Parameters;
use Laminas\Stdlib\Request as StdlibRequest;
use Laminas\Stdlib\Response as StdlibResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;
use function array_map;
use function array_unique;
use function md5;
use function sprintf;

#[Group('unit')]
#[Group('cache')]
final class CacheStrategyTest extends TestCase
{
    use MvcEventTrait;

    /**
     * @return array<string, array{array<string, mixed>, array<array-key, array<string, mixed>>, string, bool}>
     */
    public static function cacheSwitchProvider(): array
    {
        return [
            'master switch off'             => [['cache' => false], [], '/page', false],
            'master switch unset'           => [['cache' => null], [], '/page', false],
            'master switch not a scalar'    => [['cache' => ['on']], [], '/page', false],
            'master switch on'              => [['cache' => true], [], '/page', true],
            'master switch truthy string'   => [['cache' => '1'], [], '/page', true],
            'route turns caching off'       => [['cache' => true], ['^/api' => ['cache' => false]], '/api/x', false],
            'unmatched route leaves it on'  => [['cache' => true], ['^/api' => ['cache' => false]], '/page', true],
            'route turns caching on'        => [['cache' => false], ['^/news' => ['cache' => true]], '/news/1', true],
            'last matching route wins'      => [
                ['cache' => true],
                ['^/a' => ['cache' => false], '^/a/b' => ['cache' => true]],
                '/a/b',
                true,
            ],
            'numeric route pattern matches' => [['cache' => true], [404 => ['cache' => false]], '/404', false],
            'route after an unmatched one'  => [
                ['cache' => true],
                ['^/x' => ['cache' => true], '^/a' => ['cache' => false]],
                '/a',
                false,
            ],
            'route without a cache flag'    => [['cache' => true], ['^/a' => ['ttl' => 5]], '/a', true],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requestVariableProvider(): array
    {
        return [
            'query' => ['query'],
            'post'  => ['post'],
            'files' => ['files'],
        ];
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function uncacheableRequestProvider(): array
    {
        return [
            'POST'          => ['http://example.com/page', HttpRequest::METHOD_POST, []],
            'PUT'           => ['http://example.com/page', HttpRequest::METHOD_PUT, []],
            'Range header'  => ['http://example.com/page', HttpRequest::METHOD_GET, ['Range' => 'bytes=0-10']],
            'Authorization' => ['http://example.com/page', HttpRequest::METHOD_GET, ['Authorization' => 'Basic eDp5']],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unknownRoleIdentityProvider(): array
    {
        return [
            'string identity'           => ['jane'],
            'object without getRoleId'  => [new stdClass()],
            'role that is not a scalar' => [new RoleIdentity(['admin'])],
            'role that is null'         => [new RoleIdentity(null)],
        ];
    }

    #[Test]
    public function attachesAtPriorityOneByDefaultWhenTheConfiguredOneIsNotAnInteger(): void
    {
        $listener   = new CacheStrategy([Application::class => ['dispatch' => null]]);
        $priorities = [];
        $shared     = $this->createStub(SharedEventManagerInterface::class);
        $shared->method('attach')
            ->willReturnCallback(static function (string $id, string $event, callable $callback, int $priority) use (
                &$priorities,
            ): void {
                $priorities[$event] = $priority;
            });

        $listener->attach($this->eventsWith($shared));

        static::assertSame(1, $priorities['dispatch']);
    }

    #[Test]
    public function attachesConfiguredEventsAndTheDisableEventToTheSharedManager(): void
    {
        $listener = new CacheStrategy([Application::class => ['dispatch' => -100, 'finish' => 100]]);
        $attached = [];
        $shared   = $this->createStub(SharedEventManagerInterface::class);
        $shared->method('attach')
            ->willReturnCallback(static function (string $id, string $event, callable $callback, int $priority) use (
                &$attached,
            ): void {
                $attached[] = [$id, $event, $priority];
            });

        $listener->attach($this->eventsWith($shared));

        static::assertSame(
            [
                [Application::class, 'dispatch', -100],
                [Application::class, 'finish', 100],
                [Application::class, CacheStrategy::EVENT_DISABLE, 100],
            ],
            $attached,
        );
    }

    #[Test]
    public function attachIsANoOpWithoutASharedManager(): void
    {
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())->method('getSharedManager')->willReturn(null);

        (new CacheStrategy([Application::class => ['dispatch' => 1]]))->attach($events);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<array-key, array<string, mixed>> $routes
     */
    #[Test]
    #[DataProvider('cacheSwitchProvider')]
    public function consultsTheCacheOptionAfterApplyingTheLastMatchingRoute(
        array $options,
        array $routes,
        string $path,
        bool $consulted,
    ): void {
        $request = $this->request("http://example.com{$path}");

        static::assertSame($consulted, null !== $this->keyFor($request, $options, $routes));
    }

    #[Test]
    public function detachesTheSameListenersItAttached(): void
    {
        $listener = new CacheStrategy([Application::class => ['dispatch' => 1]]);
        $attached = [];
        $detached = [];
        $shared   = $this->createStub(SharedEventManagerInterface::class);
        $shared->method('attach')
            ->willReturnCallback(static function (string $id, string $event, callable $callback) use (
                &$attached,
            ): void {
                $attached[$event] = $callback;
            });
        $shared->method('detach')
            ->willReturnCallback(static function (callable $callback, string $id, string $event) use (
                &$detached,
            ): void {
                $detached[$event] = $callback;
            });
        $events = $this->eventsWith($shared);

        $listener->attach($events);
        $listener->detach($events);

        static::assertSame($attached, $detached);
    }

    #[Test]
    public function detachIsANoOpWithoutASharedManager(): void
    {
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())->method('getSharedManager')->willReturn(null);

        (new CacheStrategy([Application::class => ['dispatch' => 1]]))->detach($events);
    }

    #[Test]
    public function doesNothingOnceDisabled(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->never())->method('hasItem');
        $listener = $this->listener($storage);
        $listener->disable();

        static::assertFalse($listener->onDispatch($this->event($this->request())));
    }

    #[Test]
    public function doesNothingWithoutACacheStorage(): void
    {
        static::assertFalse(
            (new CacheStrategy([], sapi: 'fpm-fcgi'))->setOptions(['cache' => true])->onDispatch(
                $this->event($this->request()),
            ),
        );
    }

    #[Test]
    public function doesNotReadTheStorageWhenItHoldsNoEntryForTheKey(): void
    {
        $storage = $this->missingStorage();
        $storage->expects($this->never())->method('getItem');

        $this->listener($storage)->onDispatch($this->event($this->request()));
    }

    #[Test]
    public function doesNotStoreAgainAfterServingAHit(): void
    {
        $storage = $this->storageHolding($this->okResponse());
        $storage->expects($this->never())->method('setItem');
        $listener = $this->listener($storage);

        $listener->onDispatch($this->event($this->request()));
        $listener->onFinish($this->finishEvent($this->okResponse()));
    }

    #[Test]
    public function doesNotStoreALaterUncacheableRequestUnderAnEarlierKey(): void
    {
        $storage = $this->missingStorage();
        $storage->expects($this->never())->method('setItem');
        $listener = $this->listener($storage);

        $listener->onDispatch($this->event($this->request()));
        $listener->onDispatch($this->event($this->request(method: HttpRequest::METHOD_POST)));
        $listener->onFinish($this->finishEvent($this->okResponse()));
    }

    #[Test]
    public function doesNotStoreNonOkResponses(): void
    {
        $storage = $this->missingStorage();
        $storage->expects($this->never())->method('setItem');
        $listener = $this->listener($storage);
        $response = $this->okResponse();
        $response->setStatusCode(404);

        $listener->onDispatch($this->event($this->request()));
        $listener->onFinish($this->finishEvent($response));
    }

    #[Test]
    public function doesNotStoreResponsesThatAreNotHttpResponses(): void
    {
        $storage = $this->missingStorage();
        $storage->expects($this->never())->method('setItem');
        $listener = $this->listener($storage);

        $listener->onDispatch($this->event($this->request()));
        $listener->onFinish($this->finishEvent(new StdlibResponse()));
    }

    #[Test]
    public function doesNotStoreWhenDisabledDuringTheRequest(): void
    {
        $storage = $this->missingStorage();
        $storage->expects($this->never())->method('setItem');
        $listener = $this->listener($storage);

        $listener->onDispatch($this->event($this->request()));
        $listener->disable();
        $listener->onFinish($this->finishEvent($this->okResponse()));
    }

    #[Test]
    public function doesNotStoreWhenNoKeyWasBuilt(): void
    {
        $storage = $this->missingStorage();
        $storage->expects($this->never())->method('setItem');

        $this->listener($storage)->onFinish($this->finishEvent($this->okResponse()));
    }

    #[Test]
    public function fallsBackToPriorityOneWhenTheAttachPriorityIsNotAnInteger(): void
    {
        $listener = new CacheStrategy([Application::class => ['dispatch' => null]]);
        $shared   = $this->createMock(SharedEventManagerInterface::class);
        $shared->expects($this->exactly(2))
            ->method('attach')
            ->with(
                Application::class,
                static::anything(),
                static::anything(),
                static::logicalOr(1, 100),
            );

        $listener->attach($this->eventsWith($shared), priority: 'high');
    }

    #[Test]
    public function fallsBackToTheAttachPriorityWhenTheConfiguredOneIsNotAnInteger(): void
    {
        $listener   = new CacheStrategy([Application::class => ['dispatch' => null, 'finish' => '5']]);
        $priorities = [];
        $shared     = $this->createStub(SharedEventManagerInterface::class);
        $shared->method('attach')
            ->willReturnCallback(static function (string $id, string $event, callable $callback, int $priority) use (
                &$priorities,
            ): void {
                $priorities[$event] = $priority;
            });

        $listener->attach($this->eventsWith($shared), priority: 7);

        static::assertSame(['dispatch' => 7, 'finish' => 7, CacheStrategy::EVENT_DISABLE => 100], $priorities);
    }

    #[Test]
    public function honoursAConfiguredPriorityOfZero(): void
    {
        $listener   = new CacheStrategy([Application::class => ['dispatch' => 0]]);
        $priorities = [];
        $shared     = $this->createStub(SharedEventManagerInterface::class);
        $shared->method('attach')
            ->willReturnCallback(static function (string $id, string $event, callable $callback, int $priority) use (
                &$priorities,
            ): void {
                $priorities[$event] = $priority;
            });

        $listener->attach($this->eventsWith($shared), priority: 7);

        static::assertSame(0, $priorities['dispatch']);
    }

    #[Test]
    public function ignoresNonHttpRequests(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->never())->method('hasItem');

        static::assertFalse($this->listener($storage)->onDispatch($this->event(new StdlibRequest())));
    }

    #[Test]
    public function keepsEarlierRoutesWhenMoreAreAdded(): void
    {
        $keys    = [];
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('hasItem')
            ->willReturnCallback(static function (string $key) use (&$keys): bool {
                $keys[] = $key;

                return false;
            });
        $listener = $this->listener($storage, ['cache' => true], ['^/a' => ['cache' => false]]);
        $listener->setRoutes(['^/b' => ['cache' => false]]);

        $listener->onDispatch($this->event($this->request('http://example.com/a')));
        $listener->onDispatch($this->event($this->request('http://example.com/b')));

        static::assertSame([], $keys);
    }

    #[Test]
    public function keepsTheStorageTtlWhenNoneIsConfigured(): void
    {
        $options = new AdapterOptions(['ttl' => 30]);
        $ttls    = [];
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('hasItem')->willReturn(false);
        $storage->method('getOptions')->willReturn($options);
        $storage->method('setItem')
            ->willReturnCallback(static function () use ($options, &$ttls): bool {
                $ttls[] = $options->getTtl();

                return true;
            });
        $listener = $this->listener($storage, ['cache' => true, 'ttl' => 'forever']);

        $listener->onDispatch($this->event($this->request()));
        $listener->onFinish($this->finishEvent($this->okResponse()));

        static::assertSame([30], $ttls);
    }

    #[Test]
    public function keysAnonymousVisitorsAsIfThereWereNoAuthentication(): void
    {
        static::assertSame(
            $this->keyFor($this->request()),
            $this->keyFor($this->request(), auth: $this->auth(null)),
        );
    }

    #[Test]
    public function keysAPlainRequestOnItsHostThenItsPath(): void
    {
        static::assertSame(md5('example.com/page'), $this->keyFor($this->request('http://example.com/page')));
    }

    #[Test]
    public function keysOnCookiesWhenAskedTo(): void
    {
        $options = ['cache' => true, 'cache_with_cookie' => true, 'make_id_with_cookie' => true];

        static::assertNotSame(
            $this->keyFor($this->request(headers: ['Cookie' => 'theme=dark']), $options),
            $this->keyFor($this->request(headers: ['Cookie' => 'theme=light']), $options),
        );
    }

    #[Test]
    public function keysOnHostPathAndAcceptEncoding(): void
    {
        $keys = [
            $this->keyFor($this->request('http://example.com/page')),
            $this->keyFor($this->request('http://example.org/page')),
            $this->keyFor($this->request('http://example.com/other')),
            $this->keyFor($this->request('http://example.com/page', headers: ['Accept-Encoding' => 'gzip'])),
            $this->keyFor($this->request('http://example.com/page')),
        ];

        static::assertSame([0, 1, 2, 3], array_keys(array_unique($keys)));
    }

    #[Test]
    public function keysOnTheAuthenticatedRole(): void
    {
        static::assertNotSame(
            $this->keyFor($this->request(), auth: $this->auth(new RoleIdentity('admin'))),
            $this->keyFor($this->request(), auth: $this->auth(new RoleIdentity('member'))),
        );
    }

    #[Test]
    public function keysOnThePathAlongsideTheAcceptEncoding(): void
    {
        $headers = ['Accept-Encoding' => 'gzip'];

        static::assertNotSame(
            $this->keyFor($this->request('http://example.com/page', headers: $headers)),
            $this->keyFor($this->request('http://example.com/other', headers: $headers)),
        );
    }

    #[Test]
    #[DataProvider('requestVariableProvider')]
    public function keysOnVariablesWhenAskedTo(string $variable): void
    {
        $options = ['cache' => true, "cache_with_{$variable}" => true, "make_id_with_{$variable}" => true];

        static::assertNotSame(
            $this->keyFor($this->requestWith($variable, 'a'), $options),
            $this->keyFor($this->requestWith($variable, 'b'), $options),
        );
    }

    #[Test]
    #[DataProvider('requestVariableProvider')]
    public function leavesVariablesOutOfTheKeyUnlessAskedToKeyOnThem(string $variable): void
    {
        static::assertSame(
            $this->keyFor($this->request()),
            $this->keyFor($this->requestWith($variable), ['cache' => true, "cache_with_{$variable}" => true]),
        );
    }

    #[Test]
    public function neverCachesUnderTheCliSapi(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->never())->method('hasItem');
        $listener = (new CacheStrategy([]))->setCache($storage)
            ->setOptions(['cache' => true]);

        static::assertFalse($listener->onDispatch($this->event($this->request())));
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('uncacheableRequestProvider')]
    public function neverCachesUnsafeMethodsRangeOrAuthorizedRequests(string $uri, string $method, array $headers): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->never())->method('hasItem');

        static::assertFalse(
            $this->listener($storage)->onDispatch($this->event($this->request($uri, $method, $headers))),
        );
    }

    #[Test]
    public function refusesToCacheARequestCarryingCookiesUnlessAllowed(): void
    {
        $request = $this->request(headers: ['Cookie' => 'theme=dark']);

        static::assertNull($this->keyFor($request, ['cache' => true, 'cache_with_cookie' => false]));
    }

    #[Test]
    #[DataProvider('requestVariableProvider')]
    public function refusesToCacheARequestCarryingVariablesItMayNotCacheWith(string $variable): void
    {
        static::assertNull($this->keyFor($this->requestWith($variable), [
            'cache'                  => true,
            "cache_with_{$variable}" => false,
        ]));
    }

    #[Test]
    #[DataProvider('unknownRoleIdentityProvider')]
    public function refusesToCacheForAnIdentityWithoutAKnownRole(mixed $identity): void
    {
        static::assertNull($this->keyFor($this->request(), auth: $this->auth($identity)));
    }

    #[Test]
    public function rejectsAConfiguredEventWithoutAListenerMethod(): void
    {
        $listener = new CacheStrategy([Application::class => ['route' => 1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'No listener method %s::onRoute() for the configured event "route".',
            CacheStrategy::class,
        ));

        $listener->attach($this->eventsWith($this->createStub(SharedEventManagerInterface::class)));
    }

    #[Test]
    public function restoresTheStorageTtlWhenStoringFails(): void
    {
        $options = new AdapterOptions(['ttl' => 30]);
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('hasItem')->willReturn(false);
        $storage->method('getOptions')->willReturn($options);
        $storage->method('setItem')->willThrowException(new CacheRuntimeException('backend down'));
        $listener = $this->listener($storage, ['cache' => true, 'ttl' => 600]);
        $listener->onDispatch($this->event($this->request()));

        try {
            $listener->onFinish($this->finishEvent($this->okResponse()));
            static::fail('Expected the storage failure to propagate.');
        } catch (CacheRuntimeException) {
            static::assertSame(30, $options->getTtl());
        }
    }

    #[Test]
    public function servesAStoredResponseWithSanitisedHeaders(): void
    {
        $stored = $this->okResponse('<p>cached</p>');
        $stored->getHeaders()
            ->addHeaders([
                'Cache-Control' => 'private',
                'Content-Type'  => 'text/html',
                'Vary'          => 'User-Agent',
                'Pragma'        => 'no-cache',
                'Expires'       => 'Thu, 01 Jan 1970 00:00:00 GMT',
                'X-PK-Cache'    => 'MISS',
            ]);
        $stored->getHeaders()->addHeader(new SetCookie('a', '1'));
        $stored->getHeaders()->addHeader(new SetCookie('b', '2'));
        $application = new Response();

        $result = $this->listener($this->storageHolding($stored))->onDispatch($this->event(
            $this->request(),
            $application,
        ));

        static::assertSame(
            [
                'headers'       => "Content-Type: text/html\r\n"
                    . "Cache-Control: no-cache\r\n"
                    . "Vary: Accept-Encoding, Cookie\r\n"
                    . "X-PK-Cache: HIT\r\n",
                'body'          => '<p>cached</p>',
                'same response' => true,
            ],
            [
                'headers'       => $application->getHeaders()->toString(),
                'body'          => $application->getContent(),
                'same response' => $result === $application,
            ],
        );
    }

    #[Test]
    public function storesTheFinishedResponseOnAMissWithSanitisedHeaders(): void
    {
        $stored   = [];
        $storage  = $this->recordingStorage($stored);
        $listener = $this->listener($storage);
        $response = $this->okResponse();
        $response->getHeaders()
            ->addHeaders([
                'Vary'         => 'User-Agent',
                'Content-Type' => 'text/html',
                'X-PK-Cache'   => 'HIT',
            ]);
        $response->getHeaders()->addHeader(new SetCookie('session', 'secret'));

        $listener->onDispatch($this->event($this->request()));
        $listener->onFinish($this->finishEvent($response));

        static::assertSame(
            ["Content-Type: text/html\r\nVary: Accept-Encoding, Cookie\r\nX-PK-Cache: MISS\r\n"],
            array_map(static fn(HttpResponse $r): string => $r->getHeaders()->toString(), $stored),
        );
    }

    #[Test]
    public function storesUnderTheConfiguredTtlAndRestoresThePreviousOne(): void
    {
        $options = new AdapterOptions(['ttl' => 30]);
        $ttls    = [];
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('hasItem')->willReturn(false);
        $storage->method('getOptions')->willReturn($options);
        $storage->method('setItem')
            ->willReturnCallback(static function () use ($options, &$ttls): bool {
                $ttls[] = $options->getTtl();

                return true;
            });
        $listener = $this->listener($storage, ['cache' => true, 'ttl' => '600']);

        $listener->onDispatch($this->event($this->request()));
        $listener->onFinish($this->finishEvent($this->okResponse()));

        static::assertSame([600, 30], [...$ttls, $options->getTtl()]);
    }

    #[Test]
    public function treatsAHitAsAMissWhenTheApplicationResponseIsNotAnHttpResponse(): void
    {
        static::assertFalse(
            $this->listener($this->storageHolding($this->okResponse()))->onDispatch($this->event(
                $this->request(),
                new StdlibResponse(),
            )),
        );
    }

    #[Test]
    public function treatsAStoredValueThatIsNotAResponseAsAMissAndReplacesIt(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('hasItem')->willReturn(true);
        $storage->method('getItem')->willReturn('garbage');
        $storage->method('getOptions')->willReturn(new AdapterOptions());
        $storage->expects($this->once())->method('setItem');
        $listener = $this->listener($storage);

        $result = $listener->onDispatch($this->event($this->request()));
        $listener->onFinish($this->finishEvent($this->okResponse()));

        static::assertFalse($result);
    }

    private function auth(mixed $identity): AuthenticationServiceInterface
    {
        $auth = $this->createStub(AuthenticationServiceInterface::class);
        $auth->method('getIdentity')->willReturn($identity);

        return $auth;
    }

    private function eventsWith(SharedEventManagerInterface $shared): EventManagerInterface
    {
        $events = $this->createStub(EventManagerInterface::class);
        $events->method('getSharedManager')->willReturn($shared);

        return $events;
    }

    /**
     * The storage key the listener looks up for a request, or null when it
     * does not consult the cache at all.
     *
     * @param array<string, mixed> $options
     * @param array<array-key, array<string, mixed>> $routes
     */
    private function keyFor(
        HttpRequest $request,
        array $options = ['cache' => true],
        array $routes = [],
        ?AuthenticationServiceInterface $auth = null,
    ): ?string {
        $keys    = [];
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('hasItem')
            ->willReturnCallback(static function (string $key) use (&$keys): bool {
                $keys[] = $key;

                return false;
            });
        $listener = $this->listener($storage, $options, $routes);
        if (null !== $auth) {
            $listener->setAuthenticationService($auth);
        }

        $listener->onDispatch($this->event($request));

        return $keys[0] ?? null;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<array-key, array<string, mixed>> $routes
     */
    private function listener(
        StorageInterface $storage,
        array $options = ['cache' => true],
        array $routes = [],
    ): CacheStrategy {
        return (new CacheStrategy([], sapi: 'fpm-fcgi'))->setCache($storage)
            ->setOptions($options)
            ->setRoutes($routes);
    }

    /**
     * @return StorageInterface&MockObject
     */
    private function missingStorage(): StorageInterface
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('hasItem')->willReturn(false);
        $storage->method('getOptions')->willReturn(new AdapterOptions());

        return $storage;
    }

    /**
     * @param list<HttpResponse> $stored
     */
    private function recordingStorage(array &$stored): StorageInterface
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('hasItem')->willReturn(false);
        $storage->method('getOptions')->willReturn(new AdapterOptions());
        $storage->method('setItem')
            ->willReturnCallback(static function (string $key, HttpResponse $value) use (&$stored): bool {
                $stored[] = $value;

                return true;
            });

        return $storage;
    }

    private function requestWith(string $variable, string $value = 'x'): HttpRequest
    {
        $request    = $this->request();
        $parameters = new Parameters(['v' => $value]);
        match ($variable) {
            'query' => $request->setQuery($parameters),
            'post'  => $request->setPost($parameters),
            default => $request->setFiles($parameters),
        };

        return $request;
    }

    /**
     * @return StorageInterface&MockObject
     */
    private function storageHolding(HttpResponse $stored): StorageInterface
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('hasItem')->willReturn(true);
        $storage->method('getItem')->willReturn($stored);
        $storage->method('getOptions')->willReturn(new AdapterOptions());

        return $storage;
    }
}
