<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\Unit\View\Helper\Delegator;

use Contenir\Cache\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\Cache\Laminas\Mvc\View\Helper\Delegator\FormElementDisableCacheDelegator;
use Contenir\Cache\Laminas\Mvc\View\Helper\FormCsrfDisableCache;
use Laminas\Form\Element\Csrf;
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
    public function mapsCsrfElementsToTheDisableCacheHelperWhenCsrfDisablingIsOn(array $services): void
    {
        $original = $this->createMock(FormElement::class);
        $original->expects($this->once())->method('addClass')->with(Csrf::class, FormCsrfDisableCache::class);

        $this->delegate([...$services, 'Application' => $this->application()], $original);
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
    public function returnsTheHelperTheFactoryBuiltWhenCsrfDisablingIsOn(): void
    {
        $original = new FormElement();

        static::assertSame($original, $this->delegate(['Application' => $this->application()], $original));
    }

    #[Test]
    #[DataProvider('disabledSettingProvider')]
    public function returnsTheOriginalHelperUntouchedWhenCsrfDisablingIsSwitchedOff(mixed $setting): void
    {
        $original = $this->untouchedHelper();

        static::assertSame($original, $this->delegate(
            [
                'config'      => ['pagecache' => ['disable_on_csrf' => $setting]],
                'Application' => $this->application(),
            ],
            $original,
        ));
    }

    #[Test]
    public function returnsTheOriginalHelperUntouchedWhenTheApplicationServiceIsNotAnMvcApplication(): void
    {
        $original = $this->untouchedHelper();

        static::assertSame($original, $this->delegate(['Application' => new stdClass()], $original));
    }

    #[Test]
    public function returnsTheOriginalHelperUntouchedWithoutAnApplicationService(): void
    {
        $original = $this->untouchedHelper();

        static::assertSame($original, $this->delegate([], $original));
    }

    private function application(): ApplicationInterface
    {
        return $this->createStub(ApplicationInterface::class);
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

    private function untouchedHelper(): FormElement
    {
        $helper = $this->createMock(FormElement::class);
        $helper->expects($this->never())->method('addClass');

        return $helper;
    }
}
