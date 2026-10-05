<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\View\Helper\Delegator;

use Contenir\Cache\Laminas\Mvc\Listener\CacheStrategy;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Form\Element\Csrf;
use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\FormElement;
use Laminas\Mvc\ApplicationInterface;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

use function get_debug_type;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Delegator for the FormElement view helper that fires CacheStrategy::EVENT_DISABLE
 * whenever a Csrf element is rendered, so the page-cache listener does not
 * store a response containing a session-bound token.
 *
 * Toggleable via config[pagecache][disable_on_csrf]. When false, or when the
 * container has no MVC `Application` to trigger the event on, the delegator
 * returns the original helper untouched (zero overhead).
 *
 * Soft-depends on laminas/laminas-form. The delegator class is only
 * autoloaded when the FormElement service is requested, which can only
 * happen when laminas-form is installed.
 *
 * @api
 */
final class FormElementDisableCacheDelegator
{
    private static function application(mixed $service): ?ApplicationInterface
    {
        return $service instanceof ApplicationInterface ? $service : null;
    }

    private static function entry(mixed $config, string $key): mixed
    {
        return is_array($config) ? $config[$key] ?? null : null;
    }

    /**
     * @throws UnexpectedValueException When the delegated factory did not build a FormElement helper.
     */
    private static function helper(mixed $helper): FormElement
    {
        if ($helper instanceof FormElement) {
            return $helper;
        }

        throw new UnexpectedValueException(sprintf(
            'Expected the FormElement view helper, got %s.',
            get_debug_type($helper),
        ));
    }

    /**
     * @throws ContainerExceptionInterface When the config service cannot be built.
     */
    private static function isEnabled(ContainerInterface $container): bool
    {
        return self::isOn(self::entry(
            self::entry($container->has('config') ? $container->get('config') : [], 'pagecache'),
            'disable_on_csrf',
        ));
    }

    /**
     * Unset means on; otherwise the setting's truthiness.
     */
    private static function isOn(mixed $setting): bool
    {
        return null === $setting || is_scalar($setting) && (bool) $setting;
    }

    /**
     * @param array<string, mixed>|null $options
     *
     * @throws ContainerExceptionInterface When the config or Application service cannot be built.
     * @throws UnexpectedValueException When the delegated factory did not build a FormElement helper.
     *
     * @mago-expect analysis:unused-parameter The service manager's delegator signature passes $name and $options; neither is needed.
     * @mago-expect analysis:extend-final-class laminas-form 3 marks FormElement `@final` but not `final`; dropping the subclass is a design change left to the maintainers.
     */
    public function __invoke(
        ContainerInterface $container,
        string $name,
        callable $callback,
        ?array $options = null,
    ): FormElement {
        $original    = self::helper($callback());
        $application = self::isEnabled($container) && $container->has('Application')
            ? self::application($container->get('Application'))
            : null;

        if (null === $application) {
            return $original;
        }

        return new class($application->getEventManager()) extends FormElement {
            public function __construct(
                private readonly EventManagerInterface $events,
            ) {}

            #[Override]
            public function render(ElementInterface $element): string
            {
                if ($element instanceof Csrf) {
                    $this->events->trigger(CacheStrategy::EVENT_DISABLE);
                }

                return parent::render($element);
            }
        };
    }
}
