<?php

declare(strict_types=1);

namespace Contenir\PageCache\Laminas\Mvc\Tests\Integration\View\Helper\Delegator;

use Contenir\PageCache\Laminas\Mvc\ConfigProvider;
use Contenir\PageCache\Laminas\Mvc\Tests\Trait\CachingApplicationTrait;
use Contenir\PageCache\Laminas\Mvc\Tests\Trait\MvcEventTrait;
use Laminas\Form\ConfigProvider as FormConfigProvider;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Text;
use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\FormElement;
use Laminas\Form\View\Helper\FormRow;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Mvc\ApplicationInterface;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Validator\Csrf as CsrfValidator;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

use function iterator_count;

#[Group('integration')]
#[Group('cache')]
final class FormElementDisableCacheDelegatorTest extends TestCase
{
    use CachingApplicationTrait;
    use MvcEventTrait;

    /**
     * @return array<string, array{class-string}>
     */
    public static function csrfRenderingHelperProvider(): array
    {
        return [
            'formElement' => [FormElement::class],
            'formRow'     => [FormRow::class],
        ];
    }

    #[Test]
    public function aCsrfPageIsCachedWhenTheSiteOptsOut(): void
    {
        $this->dispatch($this->event($this->request()));
        $this->finish($this->okResponse($this->render(FormElement::class, $this->csrf('token'), false)));

        static::assertSame(1, iterator_count($this->storage->getIterator()));
    }

    #[Test]
    public function aCsrfPageServesAFreshTokenOnTheNextRequest(): void
    {
        $this->dispatch($this->event($this->request()));
        $this->finish($this->okResponse($this->render(FormElement::class, $this->csrf('first'))));

        $cached = $this->dispatch($this->event($this->request()));
        $html   = $this->render(FormElement::class, $this->csrf('second'));

        static::assertSame(
            [false, '<input type="hidden" name="csrf" value="second">'],
            [$cached instanceof Response, $html],
        );
    }

    /**
     * @param class-string $helper
     */
    #[Test]
    #[DataProvider('csrfRenderingHelperProvider')]
    public function aPageRenderingACsrfTokenIsNotCached(string $helper): void
    {
        $this->dispatch($this->event($this->request()));
        $html = $this->render($helper, $this->csrf('token'));
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
        $this->finish($this->okResponse($this->render(FormElement::class, new Text('name'))));

        static::assertSame(1, iterator_count($this->storage->getIterator()));
    }

    /**
     * @param class-string $helper
     */
    #[Test]
    #[DataProvider('csrfRenderingHelperProvider')]
    public function rendersCsrfElementsExactlyAsLaminasFormDoes(string $helper): void
    {
        static::assertSame(
            $this->helper($helper, $this->helpers([]))($this->csrf('token')),
            $this->render($helper, $this->csrf('token')),
        );
    }

    /**
     * @param class-string $helper
     */
    #[Test]
    #[DataProvider('csrfRenderingHelperProvider')]
    public function rendersOtherElementsExactlyAsLaminasFormDoes(string $helper): void
    {
        $text = (new Text('name'))->setLabel('Name')
            ->setValue('Ada');

        static::assertSame(
            $this->helper($helper, $this->helpers([]))($text),
            $this->render($helper, $text),
        );
    }

    #[Test]
    public function theFormElementHelperIsTheOneLaminasFormBuilds(): void
    {
        static::assertInstanceOf(FormElement::class, $this->helpers($this->config(true))->get('formElement'));
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCachingApplication();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(bool $disableOnCsrf): array
    {
        return ArrayUtils::merge((new ConfigProvider())(), ['pagecache' => ['disable_on_csrf' => $disableOnCsrf]]);
    }

    private function csrf(string $hash): Csrf
    {
        $validator = $this->createStub(CsrfValidator::class);
        $validator->method('getHash')->willReturn($hash);

        return (new Csrf('csrf'))->setCsrfValidator($validator);
    }

    /**
     * @param class-string $name
     */
    private function helper(string $name, HelperPluginManager $helpers): callable
    {
        $helper = $helpers->get($name);
        static::assertIsCallable($helper);

        return $helper;
    }

    /**
     * A view helper manager on an application container, wired to a renderer
     * the way laminas-mvc wires the `ViewHelperManager` service.
     *
     * @param array<string, mixed> $config
     */
    private function helpers(array $config): HelperPluginManager
    {
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getEventManager')->willReturn($this->events);
        $helperConfig = ArrayUtils::merge(
            (new FormConfigProvider())->getViewHelperConfig(),
            $config['view_helpers'] ?? [],
        );
        $services = new ServiceManager([
            'services'  => ['config' => $config, 'Application' => $application],
            'factories' => [
                'ViewHelperManager' =>
                    static fn(ContainerInterface $container): HelperPluginManager => new HelperPluginManager(
                        $container,
                        $helperConfig,
                    ),
            ],
        ]);
        $helpers = $services->get('ViewHelperManager');
        (new PhpRenderer())->setHelperPluginManager($helpers);

        return $helpers;
    }

    /**
     * @param class-string $helper
     */
    private function render(string $helper, ElementInterface $element, bool $disableOnCsrf = true): string
    {
        $html = $this->helper($helper, $this->helpers($this->config($disableOnCsrf)))($element);
        static::assertIsString($html);

        return $html;
    }
}
