<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Unit\View\Helper\Delegator;

use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Contenir\Cache\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\Cache\Laminas\Mvc\View\Helper\Delegator\FormElementDisableCacheDelegator;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Text;
use Laminas\Form\View\Helper\FormElement;
use Laminas\Mvc\ApplicationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

#[Group('unit')]
#[Group('cache')]
final class FormElementDisableCacheDelegatorTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function disabledSettingProvider(): array
    {
        return [
            'false'        => [false],
            'zero'         => [0],
            'empty string' => [''],
            'not a scalar' => [['no']],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function enabledConfigProvider(): array
    {
        return [
            'no config service'      => [[]],
            'config is not an array' => [['config' => 'nope']],
            'pagecache not an array' => [['config' => ['pagecache' => 'on']]],
            'setting unset'          => [['config' => ['pagecache' => []]]],
            'setting true'           => [['config' => ['pagecache' => ['disable_on_csrf' => true]]]],
        ];
    }

    /**
     * @param array<string, mixed> $services
     */
    #[Test]
    #[DataProvider('enabledConfigProvider')]
    public function decoratesTheHelperWhenCsrfDisablingIsOn(array $services): void
    {
        $original = new FormElement();

        static::assertNotSame($original, $this->delegate(
            [...$services, 'Application' => $this->application($this->createStub(EventManagerInterface::class))],
            $original,
        ));
    }

    #[Test]
    public function doesNotTriggerTheDisableEventForOtherElements(): void
    {
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->never())->method('trigger');

        $this->delegate(['Application' => $this->application($events)], new FormElement())->render(new Text('name'));
    }

    #[Test]
    public function rejectsADelegatedFactoryThatDoesNotBuildAFormElementHelper(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected the FormElement view helper, got stdClass.');

        (new FormElementDisableCacheDelegator())(
            new InMemoryContainer(),
            FormElement::class,
            static fn(): stdClass => new stdClass(),
        );
    }

    #[Test]
    #[DataProvider('disabledSettingProvider')]
    public function returnsTheOriginalHelperWhenCsrfDisablingIsSwitchedOff(mixed $setting): void
    {
        $original = new FormElement();

        static::assertSame($original, $this->delegate(
            [
                'config'      => ['pagecache' => ['disable_on_csrf' => $setting]],
                'Application' => $this->application($this->createStub(EventManagerInterface::class)),
            ],
            $original,
        ));
    }

    #[Test]
    public function returnsTheOriginalHelperWhenTheApplicationServiceIsNotAnMvcApplication(): void
    {
        $original = new FormElement();

        static::assertSame($original, $this->delegate(['Application' => new stdClass()], $original));
    }

    #[Test]
    public function returnsTheOriginalHelperWithoutAnApplicationService(): void
    {
        $original = new FormElement();

        static::assertSame($original, $this->delegate([], $original));
    }

    #[Test]
    public function triggersTheDisableEventWhenRenderingACsrfElement(): void
    {
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())->method('trigger')->with(CacheStrategy::EVENT_DISABLE);

        $this->delegate(['Application' => $this->application($events)], new FormElement())->render(new Csrf('csrf'));
    }

    private function application(EventManagerInterface $events): ApplicationInterface
    {
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getEventManager')->willReturn($events);

        return $application;
    }

    /**
     * @param array<string, mixed> $services
     */
    private function delegate(array $services, FormElement $original): FormElement
    {
        return (new FormElementDisableCacheDelegator())(
            new InMemoryContainer($services),
            FormElement::class,
            static fn(): FormElement => $original,
        );
    }
}
