<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Unit\View\Helper;

use Contenir\PageCache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\PageCache\Laminas\Mvc\View\Helper\FormCsrfDisableCache;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Form\Element\Csrf;
use Laminas\Form\View\Helper\FormHidden;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('cache')]
final class FormCsrfDisableCacheTest extends TestCase
{
    #[Test]
    public function invokingWithAnElementRendersIt(): void
    {
        $csrf = new Csrf('csrf');

        static::assertSame('<input name="csrf">', $this->helper($this->createStub(EventManagerInterface::class), $csrf)(
            $csrf,
        ));
    }

    #[Test]
    public function invokingWithoutAnElementReturnsTheHelper(): void
    {
        $helper = $this->helper($this->createStub(EventManagerInterface::class), new Csrf('csrf'));

        static::assertSame($helper, $helper());
    }

    #[Test]
    public function rendersTheElementThroughTheHiddenHelper(): void
    {
        $csrf = new Csrf('csrf');

        static::assertSame(
            '<input name="csrf">',
            $this->helper($this->createStub(EventManagerInterface::class), $csrf)->render($csrf),
        );
    }

    #[Test]
    public function triggersTheDisableEventWhenRendering(): void
    {
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())->method('trigger')->with(CacheStrategy::EVENT_DISABLE);
        $csrf = new Csrf('csrf');

        $this->helper($events, $csrf)->render($csrf);
    }

    private function helper(EventManagerInterface $events, Csrf $csrf): FormCsrfDisableCache
    {
        $hidden = $this->createStub(FormHidden::class);
        $hidden->method('render')->willReturnMap([[$csrf, '<input name="csrf">']]);

        return new FormCsrfDisableCache($events, $hidden);
    }
}
