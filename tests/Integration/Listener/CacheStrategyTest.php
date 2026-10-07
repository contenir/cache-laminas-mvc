<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Integration\Listener;

use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\PageCache\Laminas\Mvc\Tests\Trait\CachingApplicationTrait;
use Contenir\PageCache\Laminas\Mvc\Tests\Trait\MvcEventTrait;
use Laminas\Http\PhpEnvironment\Response;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function headers_sent;
use function iterator_count;
use function ob_flush;

#[Group('integration')]
#[Group('cache')]
final class CacheStrategyTest extends TestCase
{
    use CachingApplicationTrait;
    use MvcEventTrait;

    #[Test]
    public function doesNotStoreAResponseOnceTheDisableEventFires(): void
    {
        $this->dispatch($this->event($this->request()));
        $this->events->trigger(CacheStrategy::EVENT_DISABLE);
        $this->finish($this->okResponse());

        static::assertSame(0, iterator_count($this->storage->getIterator()));
    }

    /**
     * Once PHP has sent its headers, removing the Pragma and Expires it queued
     * can only warn, so the hit path leaves them alone. Sending headers takes
     * real output, so this runs in its own process and flushes a single byte
     * to that process's discarded stdout.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function servesAHitWithoutWarningsAfterPhpHasSentHeaders(): void
    {
        $this->dispatch($this->event($this->request()));
        $this->finish($this->okResponse('<p>fresh</p>'));
        echo ' ';
        ob_flush();

        $hit = $this->dispatch($this->event($this->request()));

        static::assertSame(
            [true, '<p>fresh</p>'],
            [headers_sent(), $hit instanceof Response ? $hit->getContent() : null],
        );
    }

    #[Test]
    public function servesTheResponseStoredOnTheFirstRequestToTheSecond(): void
    {
        $this->dispatch($this->event($this->request()));
        $this->finish($this->okResponse('<p>fresh</p>'));

        $hit = $this->dispatch($this->event($this->request()));

        static::assertSame(
            ['<p>fresh</p>', 'HIT'],
            [
                $hit instanceof Response ? $hit->getContent() : null,
                $hit?->getHeaders()->get('X-PK-Cache')?->getFieldValue(),
            ],
        );
    }

    #[Test]
    public function stopsCachingOnceDetached(): void
    {
        $this->listener->detach($this->events);

        $this->dispatch($this->event($this->request()));
        $this->finish($this->okResponse());

        static::assertSame(0, iterator_count($this->storage->getIterator()));
    }

    #[Test]
    public function storesOneEntryPerCacheableRequest(): void
    {
        $this->dispatch($this->event($this->request()));
        $this->finish($this->okResponse());

        static::assertSame(1, iterator_count($this->storage->getIterator()));
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCachingApplication();
    }
}
