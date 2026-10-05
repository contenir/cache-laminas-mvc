<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\Cache\Laminas\Mvc\Factory\FormCsrfDisableCacheFactory;
use Contenir\Cache\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\Cache\Laminas\Mvc\View\Helper\FormCsrfDisableCache;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Form\View\Helper\FormHidden;
use Laminas\Mvc\ApplicationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[Group('unit')]
#[Group('cache')]
final class FormCsrfDisableCacheFactoryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function wrongServiceProvider(): array
    {
        return [
            'Application'       => [
                'Application',
                'contenir/cache-laminas-mvc: service "Application" must resolve to a Laminas\Mvc\ApplicationInterface; got stdClass.',
            ],
            'ViewHelperManager' => [
                'ViewHelperManager',
                'contenir/cache-laminas-mvc: service "ViewHelperManager" must resolve to a Psr\Container\ContainerInterface; got stdClass.',
            ],
            'formhidden'        => [
                'formhidden',
                'contenir/cache-laminas-mvc: service "formhidden" must resolve to a Laminas\Form\View\Helper\FormHidden; got stdClass.',
            ],
        ];
    }

    #[Test]
    public function buildsTheHelperOnTheApplicationEventsAndTheHiddenHelper(): void
    {
        $events = $this->createStub(EventManagerInterface::class);
        $hidden = new FormHidden();

        static::assertEquals(
            new FormCsrfDisableCache($events, $hidden),
            (new FormCsrfDisableCacheFactory())($this->container($events, $hidden)),
        );
    }

    #[Test]
    #[DataProvider('wrongServiceProvider')]
    public function rejectsAServiceOfTheWrongType(string $wrong, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new FormCsrfDisableCacheFactory())($this->container(
            $this->createStub(EventManagerInterface::class),
            new FormHidden(),
            $wrong,
        ));
    }

    private function container(
        EventManagerInterface $events,
        FormHidden $hidden,
        string $wrong = '',
    ): InMemoryContainer {
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getEventManager')->willReturn($events);
        $helpers = new InMemoryContainer(['formhidden' => 'formhidden' === $wrong ? new stdClass() : $hidden]);

        return new InMemoryContainer([
            'Application'       => 'Application' === $wrong ? new stdClass() : $application,
            'ViewHelperManager' => 'ViewHelperManager' === $wrong ? new stdClass() : $helpers,
        ]);
    }
}
