<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Integration\View\Helper\Delegator;

use Contenir\Cache\Laminas\Mvc\ConfigProvider;
use Contenir\Cache\Laminas\Mvc\Tests\Trait\CachingApplicationTrait;
use Contenir\Cache\Laminas\Mvc\Tests\Trait\MvcEventTrait;
use Laminas\Form\ConfigProvider as FormConfigProvider;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Text;
use Laminas\Form\View\Helper\FormElement;
use Laminas\Mvc\ApplicationInterface;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Validator\Csrf as CsrfValidator;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function iterator_count;

#[Group('integration')]
#[Group('cache')]
final class FormElementDisableCacheDelegatorTest extends TestCase
{
    use CachingApplicationTrait;
    use MvcEventTrait;

    #[Test]
    public function aCsrfPageIsCachedWhenTheSiteOptsOut(): void
    {
        $this->dispatch($this->event($this->request()));
        $this->finish($this->okResponse($this->formElementHelper(false)->render($this->csrf())));

        static::assertSame(1, iterator_count($this->storage->getIterator()));
    }

    #[Test]
    public function aPageRenderingACsrfTokenIsNotCached(): void
    {
        $this->dispatch($this->event($this->request()));
        $html = $this->formElementHelper(true)->render($this->csrf());
        $this->finish($this->okResponse($html));

        static::assertSame(
            ['<input type="hidden" name="csrf" value="token">', 0],
            [$html, iterator_count($this->storage->getIterator())],
        );
    }

    #[Test]
    public function aPageRenderingOtherElementsIsCached(): void
    {
        $this->dispatch($this->event($this->request()));
        $html = $this->formElementHelper(true)->render(new Text('name'));
        $this->finish($this->okResponse($html));

        static::assertSame(1, iterator_count($this->storage->getIterator()));
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCachingApplication();
    }

    private function csrf(): Csrf
    {
        $validator = $this->createStub(CsrfValidator::class);
        $validator->method('getHash')->willReturn('token');

        return (new Csrf('csrf'))->setCsrfValidator($validator);
    }

    private function formElementHelper(bool $disableOnCsrf): FormElement
    {
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getEventManager')->willReturn($this->events);
        $config   = ArrayUtils::merge((new ConfigProvider())(), ['pagecache' => ['disable_on_csrf' => $disableOnCsrf]]);
        $services = new ServiceManager(['services' => ['config' => $config, 'Application' => $application]]);
        $helpers  = new HelperPluginManager(
            $services,
            ArrayUtils::merge((new FormConfigProvider())->getViewHelperConfig(), $config['view_helpers']),
        );
        (new PhpRenderer())->setHelperPluginManager($helpers);

        return $helpers->get(FormElement::class);
    }
}
